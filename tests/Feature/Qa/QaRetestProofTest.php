<?php

namespace Tests\Feature\Qa;

use App\Exceptions\ApiException;
use App\Models\QaFinding;
use App\Models\QaInventorySnapshot;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Models\User;
use App\Services\Qa\QaFindings;
use App\Services\Qa\QaRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QaRetestProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function fixture(bool $linked = false, bool $mocked = false, ?string $executedAt = null): array
    {
        $fixer = User::factory()->create();
        $qa = User::factory()->create();
        $finding = QaFinding::create(['fingerprint' => hash('sha256', Str::random(20)), 'title' => 'Proof regression', 'severity' => 'p1',
            'status' => 'fixed', 'affected_routes' => [], 'test_keys' => $linked ? ['Proof::regression'] : [], 'owner_id' => $fixer->id,
            'fixed_by' => $fixer->id, 'fixed_at' => now()->subMinutes(2), 'fix_commit' => 'abcdef1234', 'created_by' => $qa->id]);
        $run = app(QaRuns::class)->importJunit('<testsuites><testsuite><testcase classname="Proof" name="regression" assertions="2"/></testsuite></testsuites>',
            ['suite' => 'full', 'environment' => 'staging', 'git_sha' => 'abcdef1234', 'source' => 'manual', 'mocked' => $mocked, 'executed_at' => $executedAt], $qa->id);

        return [$finding, $run, $qa, QaTestResult::where('run_id', $run->id)->sole()];
    }

    private function blocked(QaFinding $finding, QaTestRun $run, User $qa, string $code): void
    {
        try {
            app(QaFindings::class)->retest($finding, $run, true, $qa);
            $this->fail('Unproven retest must not close a finding.');
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
        $this->assertSame('fixed', $finding->fresh()->status);
    }

    public function test_manual_pass_needs_unexpired_checksum_verified_result_evidence(): void
    {
        [$finding, $run, $qa, $result] = $this->fixture();
        $this->blocked($finding, $run, $qa, 'retest_evidence_required');
        $evidence = app(QaRuns::class)->storeEvidence($run, UploadedFile::fake()->createWithContent('proof.txt', 'Executed regression assertions'), $result, 'proof-request', $qa->id);
        $evidence->forceFill(['retain_until' => now()->subMinute()])->save();
        $this->blocked($finding, $run, $qa, 'retest_evidence_required');
        $evidence->forceFill(['retain_until' => now()->addDay()])->save();
        $bytes = Storage::disk('local')->get($evidence->path);
        Storage::disk('local')->put($evidence->path, 'corrupt artifact');
        $this->blocked($finding, $run, $qa, 'retest_evidence_required');
        Storage::disk('local')->put($evidence->path, $bytes);
        $this->assertSame('retest_passed', app(QaFindings::class)->retest($finding, $run, true, $qa)->status);
    }

    public function test_mocked_manual_run_cannot_close_a_finding_even_with_an_attachment(): void
    {
        [$finding, $run, $qa, $result] = $this->fixture(mocked: true);
        app(QaRuns::class)->storeEvidence($run, UploadedFile::fake()->createWithContent('proof.txt', 'Synthetic result'), $result, null, $qa->id);
        $this->blocked($finding, $run, $qa, 'retest_evidence_required');
    }

    public function test_linked_manual_result_also_requires_its_own_valid_evidence(): void
    {
        [$finding, $run, $qa, $result] = $this->fixture(linked: true);
        $this->blocked($finding, $run, $qa, 'retest_evidence_required');
        app(QaRuns::class)->storeEvidence($run, UploadedFile::fake()->createWithContent('proof.txt', 'Executed linked regression'), $result, null, $qa->id);
        $this->assertSame('retest_passed', app(QaFindings::class)->retest($finding, $run, true, $qa)->status);
    }

    public function test_old_execution_cannot_become_a_retest_by_importing_it_after_the_fix(): void
    {
        [$finding, $run, $qa] = $this->fixture(executedAt: now()->subHour()->toIso8601String());
        $this->blocked($finding, $run, $qa, 'retest_run_outdated');
    }

    public function test_later_scan_order_alone_is_not_positive_build_proof(): void
    {
        QaInventorySnapshot::create(['git_sha' => 'f00aaa1', 'totals' => [], 'diff' => []]);
        QaInventorySnapshot::create(['git_sha' => 'b00bbb2', 'totals' => [], 'diff' => []]);
        $this->assertSame('unknown', QaFindings::buildProvenance('f00aaa1', 'b00bbb2'));
        $this->assertSame('outdated', QaFindings::buildProvenance('b00bbb2', 'f00aaa1'));
    }
}
