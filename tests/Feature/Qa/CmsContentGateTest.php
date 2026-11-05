<?php

namespace Tests\Feature\Qa;

use App\Enums\PlatformRole;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\User;
use App\Services\Qa\CmsContentGate;
use App\Services\Qa\QaAcceptance;
use App\Services\Qa\QaRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CmsContentGateTest extends TestCase
{
    use RefreshDatabase;

    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sha = str_repeat('a', 40);
        Storage::fake('local');
        $this->travelTo(now()->startOfSecond());
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return json_decode(file_get_contents(base_path('requirements/CMS_ACCEPTANCE.json')), true, 64, JSON_THROW_ON_ERROR);
    }

    public function test_recommended_contract_does_not_claim_original_or_unexecuted_coverage_complete(): void
    {
        $m = $this->manifest();
        $this->assertCount(23, $m['checks']);
        $this->assertCount(5, $m['denominators']);
        $r = app(CmsContentGate::class)->evaluate($m, $this->sha);
        $this->assertFalse($r['evidence_complete']);
        $this->assertFalse($r['release_approved']);
        $this->assertCount(28, $r['definitions']);
        $this->assertContains('cms_contract_unmapped_or_invalid', $r['blockers']);
        $this->artisan('shopbonus:cms-content-check', ['--sha' => $this->sha])->expectsOutputToContain('configured_staging_deployment_required')->assertFailed();
        $this->app['env'] = 'staging';
        config(['qa.release_sha' => $this->sha]);
        $this->artisan('shopbonus:cms-content-check', ['--sha' => $this->sha])->assertFailed();
        $m['state'] = 'reviewed';
        $m['frontend_git_sha'] = str_repeat('b', 40);
        $m['source']['path'] = '../.env';
        unset($m['checks'][22]);
        $r = app(CmsContentGate::class)->evaluate($m, $this->sha);
        $this->assertContains('cms_recommended_source_missing_or_changed', $r['blockers']);
        $this->assertContains('executor_unmapped:CMS-T23', $r['blockers']);
    }

    /** @return array<string,mixed> */
    private function entry(string $id, string $key): array
    {
        return ['id' => $id, 'package' => $id, 'test_key' => 'SyntheticCMS::'.$key, 'case_version' => 1, 'assertions' => ['Synthetic expected/actual outcomes, not genuine staging acceptance'], 'min_assertions' => 1, 'environment' => 'staging', 'executor' => 'Synthetic execution fixture', 'instructions' => ['Execute synthetic test-only fixture'], 'required_evidence' => ['http', 'database', 'request_ids', 'screenshot'], 'review_evidence_id' => strtolower((string) Str::ulid())];
    }

    public function test_model_and_denominator_requirements_cannot_be_removed_from_mapping(): void
    {
        $proofs = app(QaAcceptance::class);
        foreach (['CMS-T09', 'CMS-T22'] as $id) {
            $d = $this->entry($id, $id);
            $d['provider'] = 'openai';
            $this->assertSame(['cms_required_evidence_missing'], $proofs->checkCms($d, $this->sha, str_repeat('c', 64), 'synthetic-v1'));
        }
        foreach (['CMS-D01', 'CMS-D05'] as $id) {
            $this->assertSame(['cms_required_evidence_missing'], $proofs->checkCms($this->entry($id, $id), $this->sha, str_repeat('c', 64), 'synthetic-v1'));
        }
        $d = $this->entry('CMS-T01', 'missing-proof');
        $this->assertSame(['review_evidence_unavailable'], $proofs->checkCms($d, $this->sha, str_repeat('c', 64), 'synthetic-v1'));
    }

    /** Genuine-looking flags here are deliberately synthetic test data, not factual provider/staging proof.
     * @param  list<string>  $ids
     * @param  list<string>  $covered
     * @return array<string,mixed>
     */
    private function coverageProof(string $key, array $ids, array $covered, bool $known = true, bool $mocked = false): array
    {
        $d = $this->entry('CMS-D05', $key);
        $d['coverage_kind'] = 'staging_executors';
        $d['required_evidence'][] = 'cms_coverage';
        $executor = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->platformRoles()->create(['role' => PlatformRole::QaTester, 'granted_via' => 'console', 'granted_at' => now()]);
        $runs = app(QaRuns::class);
        $run = $runs->importJunit('<testsuite><testcase class="SyntheticCMS" name="'.$key.'" assertions="2"/></testsuite>', ['suite' => 'full', 'environment' => 'staging', 'git_sha' => $this->sha, 'mocked' => $mocked, 'executed_at' => now()->subMinute()->toIso8601String()], $executor->id);
        QaTestCase::where('key', $d['test_key'])->update(['status' => 'approved', 'approved_by' => $reviewer->id, 'approved_at' => now()]);
        $result = QaTestResult::where('run_id', $run->id)->sole();
        $data = ['synthetic_check' => $key, 'request' => ['method' => 'GET', 'url' => 'https://synthetic.example.test'], 'response' => ['status' => 200], 'request_id' => 'synthetic-request', 'request_ids' => ['synthetic-request'], 'before' => [], 'after' => [], 'source_references' => ['synthetic-only'], 'denominator_id' => 'CMS-D05', 'coverage_kind' => 'staging_executors', 'deployment_sha' => $this->sha, 'known' => $known, 'ids' => $ids, 'covered_ids' => $covered];
        $artifact = $runs->storeEvidence($run, UploadedFile::fake()->createWithContent('synthetic.json', json_encode($data)), $result, 'synthetic-request', $executor->id);
        $image = $runs->storeEvidence($run, UploadedFile::fake()->image('synthetic.png', 2, 2), $result, null, $executor->id);
        $refs = [];
        foreach ($d['required_evidence'] as $kind) {
            $f = $kind === 'screenshot' ? $image : $artifact;
            $refs[] = ['kind' => $kind, 'evidence_id' => $f->public_id, 'sha256' => $f->sha256];
        }
        $record = ['check_id' => $d['id'], 'definition_sha256' => QaAcceptance::definitionHash($d), 'source_sha256' => str_repeat('c', 64), 'source_version' => 'synthetic-v1', 'deployment_sha' => $this->sha, 'environment' => 'staging', 'run_id' => $run->public_id, 'result_id' => $result->public_id, 'executor_user_id' => $executor->id, 'reviewer_user_id' => $reviewer->id, 'verdict' => 'accepted', 'reviewed_at' => now()->toIso8601String(), 'evidence' => $refs];
        $review = $runs->storeEvidence($run, UploadedFile::fake()->createWithContent('synthetic-review.json', json_encode($record)), $result, null, $reviewer->id);
        $d['review_evidence_id'] = $review->public_id;

        return $d;
    }

    public function test_coverage_uses_exact_unique_ids_known_state_and_all23_executors_not_percentages(): void
    {
        $all = array_map(fn ($n) => sprintf('CMS-T%02d', $n), range(1, 23));
        $proofs = app(QaAcceptance::class);
        $valid = $this->coverageProof('complete-synthetic', $all, $all);
        $this->assertSame([], $proofs->checkCms($valid, $this->sha, str_repeat('c', 64), 'synthetic-v1'));
        foreach ([[$all, ['CMS-T01'], true, false], [['CMS-T01'], ['CMS-T01'], true, false], [$all, $all, false, false], [array_merge($all, ['CMS-T01']), $all, true, false], [$all, $all, true, true]] as $i => $case) {
            $d = $this->coverageProof('invalid-synthetic-'.$i, ...$case);
            $this->assertNotSame([], $proofs->checkCms($d, $this->sha, str_repeat('c', 64), 'synthetic-v1'));
        }
    }
}
