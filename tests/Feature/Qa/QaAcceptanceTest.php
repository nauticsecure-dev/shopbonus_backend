<?php

namespace Tests\Feature\Qa;

use App\Enums\PlatformRole;
use App\Models\QaEndpoint;
use App\Models\QaEvidence;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\User;
use App\Services\Qa\QaAcceptance;
use App\Services\Qa\QaFrontendInventory;
use App\Services\Qa\QaRuns;
use App\Services\Qa\QaStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class QaAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private string $source;

    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->startOfSecond());
        $this->source = 'SYNTHETIC-QA-'.Str::random(10).'.txt';
        file_put_contents(base_path('requirements/'.$this->source), 'Synthetic test-only checklist, not the original PDF.');
        $this->sha = str_repeat('a', 40);
    }

    protected function tearDown(): void
    {
        unlink(base_path('requirements/'.$this->source));
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function mapping(): array
    {
        $entry = fn (string $id, int $number) => ['id' => $id, 'package' => sprintf('E2E-%02d', ($number % 25) + 1), 'test_key' => 'Synthetic::'.$id,
            'assertions' => ['Verify synthetic expected and downstream state'], 'case_version' => 1, 'min_assertions' => 1, 'environment' => 'staging', 'executor' => 'Independent test executor',
            'instructions' => ['Run reviewed synthetic test in isolated staging'], 'required_evidence' => ['http', 'database', 'request_ids', 'screenshot'], 'review_evidence_id' => (string) Str::ulid()];
        $checks = [];
        foreach (range(1, 175) as $i) {
            $checks[] = $entry('SYNTHETIC-'.$i, $i);
        }
        $subruns = [];
        foreach (QaAcceptance::PROVIDERS as $provider) {
            foreach (QaAcceptance::STAGES as $stage) {
                $row = $entry($provider.'-'.$stage, 0) + ['provider' => $provider, 'stage' => $stage];
                $row['required_evidence'][] = 'provider_confirmation';
                $subruns[] = $row;
            }
        }
        $frontend = $entry('SYNTHETIC-FRONTEND', 0);
        $frontend['required_evidence'][] = 'frontend_inventory';
        $frontend['frontend_git_sha'] = str_repeat('b', 40);

        return ['schema_version' => 1, 'state' => 'reviewed', 'source' => ['version' => 'synthetic-v1', 'path' => $this->source, 'sha256' => hash_file('sha256', base_path('requirements/'.$this->source))],
            'checks' => $checks, 'provider_subruns' => $subruns, 'frontend_discovery' => $frontend];
    }

    public function test_unmapped_source_and_missing_provider_runs_fail_closed(): void
    {
        $this->artisan('shopbonus:qa-acceptance-check', ['--sha' => $this->sha])->assertFailed();
        $this->artisan('shopbonus:qa-acceptance-check', ['--sha' => $this->sha, '--discover' => true])->expectsOutputToContain('configured_staging_deployment_required')->assertFailed();
        $mapping = $this->mapping();
        array_pop($mapping['checks']);
        $mapping['provider_subruns'] = [];
        $mapping['source']['path'] = '../.env';
        $report = app(QaAcceptance::class)->evaluate($mapping, $this->sha);
        $this->assertFalse($report['evidence_complete']);
        $this->assertContains('manifest_invalid:checks', $report['blockers']);
        $this->assertContains('approved_source_missing_or_mismatched', $report['blockers']);
        $this->assertContains('provider_subrun_missing_or_duplicate:shopify:refund', $report['blockers']);
        $this->assertSame([], $report['checks']);
    }

    public function test_staging_hook_scans_and_blocks_uncovered_routes_without_running_commands_from_mapping(): void
    {
        $this->app['env'] = 'staging';
        config(['qa.release_sha' => $this->sha]);
        $file = base_path('requirements/'.$this->source.'.json');
        file_put_contents($file, json_encode($this->mapping()));
        try {
            $this->artisan('shopbonus:qa-acceptance-check', ['--sha' => $this->sha, '--manifest' => $file, '--discover' => true])
                ->expectsOutputToContain('route_coverage_incomplete')->assertFailed();
            $this->assertDatabaseHas('qa_inventory_snapshots', ['git_sha' => $this->sha]);
            $this->assertDatabaseCount('qa_test_runs', 0);
        } finally {
            unlink($file);
        }
    }

    public function test_duplicate_identity_and_raw_junit_without_review_cannot_establish_acceptance(): void
    {
        $mapping = $this->mapping();
        $report = app(QaAcceptance::class)->evaluate($mapping, $this->sha);
        $this->assertCount(236, $report['checks']);
        $this->assertSame(['review_evidence_unavailable'], $report['checks'][0]['blockers']);
        $mapping['checks'][1] = $mapping['checks'][0];
        $report = app(QaAcceptance::class)->evaluate($mapping, $this->sha);
        $this->assertContains('duplicate_check_id:SYNTHETIC-1', $report['blockers']);
        $this->assertContains('duplicate_test_key:Synthetic::SYNTHETIC-1', $report['blockers']);
    }

    public function test_complete_synthetic_evidence_is_not_final_approval_and_revocation_corruption_and_new_failure_block_it(): void
    {
        $mapping = $this->mapping();
        $executor = User::factory()->create();
        $reviewer = User::factory()->create();
        $reviewer->platformRoles()->create(['role' => PlatformRole::QaTester, 'granted_via' => 'console', 'granted_at' => now()]);
        $entries = array_merge($mapping['checks'], $mapping['provider_subruns'], [$mapping['frontend_discovery']]);
        $xml = '<testsuite>';
        foreach ($entries as $entry) {
            $xml .= '<testcase class="Synthetic" name="'.substr($entry['test_key'], 11).'" assertions="1"/>';
        }
        $xml .= '</testsuite>';
        $runs = app(QaRuns::class);
        $run = $runs->importJunit($xml, ['suite' => 'full', 'environment' => 'staging', 'git_sha' => $this->sha, 'executed_at' => now()->subMinute()->toIso8601String()], $executor->id);
        QaTestCase::query()->update(['status' => 'approved', 'approved_by' => $reviewer->id, 'approved_at' => now()]);
        $results = QaTestResult::where('run_id', $run->id)->get()->keyBy('key');
        foreach ($entries as $index => &$entry) {
            $result = $results[$entry['test_key']];
            $body = ['synthetic_check_id' => $entry['id'], 'request' => ['method' => 'GET', 'url' => 'https://synthetic.example.test/'], 'response' => ['status' => 200], 'request_id' => 'synthetic-request', 'request_ids' => ['synthetic-request'],
                'before' => [], 'after' => [], 'source_references' => ['synthetic'], 'provider' => $entry['provider'] ?? 'synthetic', 'store_reference' => 'synthetic-store', 'source_reference' => 'synthetic-event',
                'observed_state' => 'synthetic-only', 'observed_at' => now()->toIso8601String(), 'genuine_provider' => true,
                'git_sha' => str_repeat('b', 40), 'routes' => ['synthetic'], 'actions' => ['synthetic'], 'components' => ['synthetic'], 'ui_states' => ['synthetic']];
            $artifact = $runs->storeEvidence($run, UploadedFile::fake()->createWithContent('synthetic.json', json_encode($body)), $result, 'synthetic-request', $executor->id);
            $image = $runs->storeEvidence($run, UploadedFile::fake()->image('synthetic.png', $index + 1, 1), $result, null, $executor->id);
            if (in_array('frontend_inventory', $entry['required_evidence'], true)) {
                $observed = array_fill_keys(QaFrontendInventory::KINDS, ['synthetic']);
                $trace = $runs->storeEvidence($run, UploadedFile::fake()->createWithContent('trace.json', json_encode(['synthetic' => true, 'result' => $result->public_id])), $result, null, $executor->id);
                $context = ['case_version' => 1, 'id' => 'synthetic-context', 'git_sha' => str_repeat('b', 40), 'locale' => 'en', 'actor_role' => 'qa_tester', 'ui_state' => 'loaded',
                    'route' => '/synthetic', 'viewport' => ['width' => 390, 'height' => 844], 'test_key' => $entry['test_key'], 'run_id' => $run->public_id, 'result_id' => $result->public_id,
                    'observed' => $observed, 'evidence' => ['screenshot' => ['sha256' => $image->sha256], 'trace' => ['sha256' => $trace->sha256]]];
                $body += ['schema_version' => 2, 'verification' => 'reviewed_runtime_dispositions', 'source_sha256' => str_repeat('d', 64), 'review_reference' => 'Explicitly synthetic structural fixture', 'contexts' => [$context], 'source_candidates' => $observed,
                    'dispositions' => array_fill_keys(QaFrontendInventory::KINDS, [['id' => 'synthetic', 'decision' => 'covered', 'reference' => 'Synthetic only', 'context_ids' => ['synthetic-context']]])];
                $artifact = $runs->storeEvidence($run, UploadedFile::fake()->createWithContent('frontend.json', json_encode($body)), $result, 'synthetic-request', $executor->id);
            }
            $refs = [];
            foreach ($entry['required_evidence'] as $kind) {
                $file = match ($kind) {
                    'screenshot' => $image,
                    'provider_confirmation' => $runs->storeEvidence($run, UploadedFile::fake()->createWithContent('provider.json', json_encode($body + ['artifact_kind' => $kind])), $result, null, $executor->id),
                    default => $artifact,
                };
                $refs[] = ['kind' => $kind, 'evidence_id' => $file->public_id, 'sha256' => $file->sha256];
            }
            $record = ['check_id' => $entry['id'], 'definition_sha256' => QaAcceptance::definitionHash($entry), 'source_sha256' => $mapping['source']['sha256'], 'source_version' => $mapping['source']['version'], 'deployment_sha' => $this->sha,
                'environment' => 'staging', 'run_id' => $run->public_id, 'result_id' => $result->public_id, 'executor_user_id' => $executor->id, 'reviewer_user_id' => $reviewer->id,
                'verdict' => 'accepted', 'reviewed_at' => now()->toIso8601String(), 'evidence' => $refs];
            $review = $runs->storeEvidence($run, UploadedFile::fake()->createWithContent('synthetic-review.json', json_encode($record)), $result, null, $reviewer->id);
            $entry['review_evidence_id'] = $review->public_id;
        }
        unset($entry);
        $mapping['checks'] = array_slice($entries, 0, 175);
        $mapping['provider_subruns'] = array_slice($entries, 175, 60);
        $mapping['frontend_discovery'] = $entries[235];
        $service = app(QaAcceptance::class);
        $report = $service->evaluate($mapping, $this->sha);
        $this->assertTrue($report['evidence_complete'], json_encode(array_filter($report['checks'], fn ($r) => $r['status'] !== 'evidence_complete')));
        $this->assertFalse($report['release_approved']);
        $provider = $mapping['provider_subruns'][0];
        $providerResult = $results[$provider['test_key']];
        $endpoint = QaEndpoint::create(['method' => 'GET', 'path' => '/api/v1/synthetic-provider', 'access' => 'admin', 'scope' => 'admin', 'documented' => true,
            'fingerprint' => str_repeat('a', 64), 'first_seen_sha' => $this->sha, 'changed_sha' => $this->sha, 'changed_at' => now()]);
        DB::table('qa_test_coverage')->insert(['test_case_id' => $providerResult->test_case_id, 'target_type' => 'endpoint', 'target_id' => $endpoint->id]);
        $status = fn () => app(QaStatus::class)->forEndpoints(collect([$endpoint]), $mapping)[$endpoint->id];
        $this->assertSame('pass', $status()['status']);
        $unmapped = $mapping;
        $unmapped['provider_subruns'] = [];
        $unmappedStatus = app(QaStatus::class)->forEndpoints(collect([$endpoint]), $unmapped)[$endpoint->id];
        $this->assertSame('untested', $unmappedStatus['status']);
        $this->assertTrue($unmappedStatus['evidence_missing']);
        $providerReview = QaEvidence::where('public_id', $provider['review_evidence_id'])->sole();
        $providerProof = QaEvidence::where('result_id', $providerResult->id)->where('name', 'provider.json')->sole();
        $providerProof->forceFill(['retain_until' => now()->subSecond()])->save();
        $this->assertSame('untested', $status()['status']);
        $this->assertTrue($status()['evidence_missing']);
        $this->assertTrue($runs->evidenceAvailable($providerReview));
        $providerProof->forceFill(['retain_until' => now()->addDay()])->save();
        $this->assertSame('pass', $status()['status']);
        $changed = $mapping;
        $changed['checks'][0]['assertions'][] = 'A newly added expectation needs fresh independent review';
        $this->assertSame(['review_binding_mismatch:definition_sha256'], $service->evaluate($changed, $this->sha)['checks'][0]['blockers']);
        $changed['checks'][0]['case_version'] = 2;
        $this->assertSame(['approved_unmocked_assertion_required'], $service->evaluate($changed, $this->sha)['checks'][0]['blockers']);
        $this->assertFalse($service->evaluate($mapping, str_repeat('c', 40))['evidence_complete']);
        $reviewer->platformRoles()->update(['revoked_at' => now()]);
        $this->assertSame(['independent_active_reviewer_required'], $service->evaluate($mapping, $this->sha)['checks'][0]['blockers']);
        $reviewer->platformRoles()->update(['revoked_at' => null]);
        $originalEnvironment = $run->environment;
        $run->forceFill(['environment' => 'ci'])->save();
        $this->assertSame(['exact_fresh_staging_execution_required'], $service->evaluate($mapping, $this->sha)['checks'][0]['blockers']);
        $run->forceFill(['environment' => $originalEnvironment])->save();
        $originalResult = $results[$entries[0]['test_key']];
        $originalResult->forceFill(['mocked' => true])->save();
        $this->assertSame(['approved_unmocked_assertion_required'], $service->evaluate($mapping, $this->sha)['checks'][0]['blockers']);
        $originalResult->forceFill(['mocked' => false])->save();
        $later = QaTestResult::create(['run_id' => $run->id, 'test_case_id' => $originalResult->test_case_id, 'key' => $originalResult->key, 'status' => 'fail', 'assertions' => 1, 'mocked' => false, 'duration_ms' => 1, 'created_at' => now()]);
        $this->assertSame(['latest_execution_required'], $service->evaluate($mapping, $this->sha)['checks'][0]['blockers']);
        $later->delete();
        $proof = QaEvidence::where('public_id', $mapping['checks'][0]['review_evidence_id'])->sole();
        $retainUntil = $proof->retain_until;
        $proof->forceFill(['retain_until' => now()->subSecond()])->save();
        $this->assertSame(['review_evidence_unavailable'], $service->evaluate($mapping, $this->sha)['checks'][0]['blockers']);
        $proof->forceFill(['retain_until' => $retainUntil])->save();
        Storage::disk('local')->put($proof->path, 'corrupt');
        $this->assertSame(['review_evidence_unavailable'], $service->evaluate($mapping, $this->sha)['checks'][0]['blockers']);
        $this->assertFalse($service->evaluate($mapping, $this->sha)['evidence_complete']);
    }
}
