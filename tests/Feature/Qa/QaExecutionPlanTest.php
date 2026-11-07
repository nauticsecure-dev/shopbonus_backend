<?php

namespace Tests\Feature\Qa;

use App\Models\QaEndpoint;
use App\Models\QaInventorySnapshot;
use App\Models\QaTestCase;
use App\Services\Qa\QaExecutionPlan;
use App\Services\Qa\QaRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class QaExecutionPlanTest extends TestCase
{
    use RefreshDatabase;

    private string $sha;

    private array $runners;

    private QaEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sha = str_repeat('a', 40);
        $this->endpoint = QaEndpoint::create(['method' => 'GET', 'path' => '/api/v1/synthetic-impact', 'access' => 'public', 'scope' => 'platform', 'documented' => true, 'fingerprint' => str_repeat('a', 64), 'first_seen_sha' => $this->sha, 'changed_sha' => $this->sha, 'changed_at' => now()]);
        foreach (['Affected::test', 'Other::test'] as $key) {
            QaTestCase::create(['key' => $key, 'title' => $key, 'source' => 'phpunit', 'status' => 'approved', 'version' => 2]);
        }
        DB::table('qa_test_coverage')->insert(['test_case_id' => QaTestCase::where('key', 'Affected::test')->sole()->id, 'target_type' => 'endpoint', 'target_id' => $this->endpoint->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->runners = [['id' => 'affected', 'tests' => [['key' => 'Affected::test', 'version' => 2, 'min_assertions' => 3]]], ['id' => 'other', 'tests' => [['key' => 'Other::test', 'version' => 2, 'min_assertions' => 2]]]];
    }

    private function diff(): array
    {
        return ['endpoints' => ['changed' => ['GET '.$this->endpoint->path]]];
    }

    public function test_deploy_selects_covered_impacts_but_nightly_and_empty_diff_require_the_reviewed_suite(): void
    {
        $planner = app(QaExecutionPlan::class);
        $plan = $planner->build($this->runners, 'after_deploy', $this->diff(), $this->sha);
        $this->assertSame(['Affected::test'], array_column($plan['affected']['required_tests'], 'key'));
        $this->assertSame([], $plan['other']['required_tests']);
        $nightly = $planner->build($this->runners, 'nightly', $this->diff(), $this->sha);
        $this->assertCount(1, $nightly['other']['required_tests']);
        $fallback = $planner->build($this->runners, 'after_deploy', [], $this->sha);
        $this->assertCount(1, $fallback['other']['required_tests']);
    }

    public function test_persisted_impacts_cannot_disappear_after_a_failed_attempt_and_rescan(): void
    {
        QaInventorySnapshot::create(['git_sha' => $this->sha, 'totals' => [], 'diff' => $this->diff()]);
        DB::table('qa_test_coverage')->delete();
        $this->expectException(RuntimeException::class);
        app(QaExecutionPlan::class)->build($this->runners, 'after_deploy', [], $this->sha);
    }

    public function test_unregistered_impacted_coverage_ai_sources_and_stale_versions_are_rejected(): void
    {
        foreach (['unregistered', 'ai', 'version', 'draft'] as $change) {
            $rows = $this->runners;
            QaTestCase::where('key', 'Affected::test')->update(['source' => 'phpunit', 'version' => 2, 'status' => 'approved']);
            if ($change === 'unregistered') {
                $rows = array_slice($rows, 1);
            }
            if ($change === 'ai') {
                QaTestCase::where('key', 'Affected::test')->update(['source' => 'ai']);
            }
            if ($change === 'version') {
                QaTestCase::where('key', 'Affected::test')->update(['version' => 3]);
            }
            if ($change === 'draft') {
                QaTestCase::where('key', 'Affected::test')->update(['status' => 'draft']);
            }
            try {
                app(QaExecutionPlan::class)->build($rows, 'after_deploy', $this->diff(), $this->sha);
                $this->fail('Unsafe mapping accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_missing_duplicate_unrelated_and_insufficient_assertions_import_as_blocked(): void
    {
        $plan = app(QaExecutionPlan::class)->build($this->runners, 'nightly', [], $this->sha)['affected'];
        $reports = [
            '<testcase classname="Other" name="test" assertions="3"/>',
            '<testcase classname="Affected" name="test" assertions="2"/>',
            '<testcase classname="Affected" name="test" assertions="3"/><testcase classname="Affected" name="test" assertions="3"/>',
        ];
        foreach ($reports as $xml) {
            $run = app(QaRuns::class)->importJunit('<testsuites><testsuite>'.$xml.'</testsuite></testsuites>', ['suite' => 'changed', 'environment' => 'staging', 'git_sha' => $this->sha, 'execution_plan' => $plan], null);
            $this->assertSame(0, $run->totals['pass']);
            $this->assertSame($run->totals['total'], $run->totals['blocked']);
        }
        $this->assertTrue(app(QaExecutionPlan::class)->casesMatch([['key' => 'Affected::test', 'assertions' => 3]], $plan));
        $this->assertFalse(app(QaExecutionPlan::class)->casesMatch([], $plan));
    }

    public function test_reviewed_executable_linked_to_ai_proposal_requires_both_approvals(): void
    {
        $proposal = QaTestCase::create(['key' => 'AI-synthetic-1', 'title' => 'Synthetic proposal', 'source' => 'ai', 'status' => 'approved', 'version' => 1]);
        $this->runners[0]['tests'][0]['proposal'] = ['key' => $proposal->key, 'version' => 1];
        $planner = app(QaExecutionPlan::class);
        $plan = $planner->build($this->runners, 'nightly', [], $this->sha)['affected'];
        $result = [['key' => 'Affected::test', 'assertions' => 3]];
        $this->assertTrue($planner->casesMatch($result, $plan));
        $proposal->update(['status' => 'retired']);
        $this->assertFalse($planner->casesMatch($result, $plan));
        $this->expectException(RuntimeException::class);
        $planner->build($this->runners, 'nightly', [], $this->sha);
    }
}
