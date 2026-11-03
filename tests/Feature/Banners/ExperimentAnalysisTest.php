<?php

namespace Tests\Feature\Banners;

use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Models\BannerCampaign;
use App\Models\IntegrationSyncRun;
use App\Models\QaEvidence;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Services\Banners\BannerExperiments;
use App\Services\Banners\BannerService;
use App\Services\Banners\ExperimentAnalysis;
use App\Services\Banners\ExperimentMeasurementProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\Support\ExperimentStudyFixture;
use Tests\TestCase;

/** Synthetic protocol/state tests. A mocked proof boundary is not live measurement acceptance. */
class ExperimentAnalysisTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private User $owner;

    private Webshop $shop;

    private BannerCampaign $campaign;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->startOfSecond());
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['verified_domain' => 'example.nl']);
        $banners = app(BannerService::class);
        $this->campaign = $banners->create($this->shop, 'Synthetic study', null, BannerFixture::config(), $this->owner->id);
        $banners->publish($this->campaign, $this->owner->id);
        $config = $this->campaign->draft_config;
        $config['placement']['anchor'] = 'bottom';
        $banners->saveDraft($this->campaign, $config, 1, $this->owner->id);
        $banners->publish($this->campaign, $this->owner->id);
        $this->input = ['control_version' => 1, 'variant_version' => 2, 'variant_bps' => 5000,
            'enrollment_days' => 1, 'min_visitors_per_arm' => 1000, 'minimum_effect_bps' => 50,
            'ingestion_grace_hours' => 24, 'review_reference' => 'SYNTHETIC-TEST', 'measurement_evidence_ids' => [(string) Str::ulid()]];
    }

    private function allowSyntheticProof(): void
    {
        // Only protocol mechanics use this stub; real proof rejection is tested separately.
        $proof = Mockery::mock(ExperimentMeasurementProof::class);
        $proof->shouldReceive('collect')->andReturn([]);
        $this->app->instance(ExperimentMeasurementProof::class, $proof);
    }

    private function launch(): array
    {
        $this->allowSyntheticProof();
        app(ExperimentAnalysis::class)->register($this->campaign, $this->input, $this->admin);
        app(BannerExperiments::class)->start($this->campaign, 1, 2, 5000, $this->owner->id);

        return (array) DB::table('banner_experiments')->first();
    }

    private function cohort(int $experimentId, int $control, int $variant): void
    {
        foreach (['control' => $control, 'variant' => $variant] as $arm => $count) {
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $rows[] = ['experiment_id' => $experimentId, 'visitor_hash' => hash('sha256', "$arm:$i"),
                    'token_hash' => hash('sha256', "token:$arm:$i"), 'arm' => $arm,
                    'created_at' => now(), 'expires_at' => now()->addDays(30)];
            }
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('banner_assignments')->insert($chunk);
            }
        }
    }

    public function test_actual_proof_collector_rejects_missing_sources_and_fabricated_evidence(): void
    {
        $this->expectException(ApiException::class);
        app(ExperimentAnalysis::class)->register($this->campaign, $this->input, $this->admin);
    }

    public function test_owner_cannot_register_or_finalize_and_revoked_admin_is_rechecked(): void
    {
        foreach (['owner', 'revoked'] as $actor) {
            if ($actor === 'revoked') {
                $this->admin->platformRoles()->delete();
            }
            try {
                app(ExperimentAnalysis::class)->register($this->campaign, $this->input, $actor === 'owner' ? $this->owner : $this->admin);
                $this->fail('Expected active Super Admin check.');
            } catch (ApiException $e) {
                $this->assertSame(403, $e->status);
            }
        }
        $this->assertDatabaseCount('banner_experiment_protocols', 0);
    }

    public function test_legacy_study_stays_exploratory_and_unverified(): void
    {
        $report = app(BannerExperiments::class)->start($this->campaign, 1, 2, 5000, $this->owner->id);
        $this->assertNull($report['causal_uplift']);
        $this->assertSame(['analysis_plan_missing', 'measurement_unverified'], $report['winner_evaluation']['blockers']);
        $this->assertFalse($report['winner_evaluation']['automatic_rollout']);
    }

    public function test_launch_requires_registered_versions_and_protocol_cannot_be_rewritten(): void
    {
        $this->allowSyntheticProof();
        $plan = app(ExperimentAnalysis::class)->register($this->campaign, $this->input, $this->admin);
        try {
            app(BannerExperiments::class)->start($this->campaign, null, 2, 5000, $this->owner->id);
            $this->fail('Expected pinned version guard.');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertDatabaseCount('banner_experiments', 0);
        $this->expectException(\LogicException::class);
        $plan->forceFill(['protocol' => ['metric' => 'replacement']])->save();
    }

    public function test_fixed_enrollment_preserves_existing_assignment_and_rejects_new_visitors(): void
    {
        $e = $this->launch();
        $installation = app(BannerService::class)->installation($this->shop);
        $service = app(BannerExperiments::class);
        $first = $service->assign($installation, 'existing-visitor');
        $this->assertCount(1, $first);
        $this->travel(1)->days();
        $this->assertSame($first, $service->assign($installation, 'existing-visitor'));
        $this->assertSame([], $service->assign($installation, 'new-visitor'));
        $this->assertFalse(app(ExperimentAnalysis::class)->canEnroll((object) $e));
    }

    public function test_readout_rejects_early_finalization_and_early_stopping(): void
    {
        $e = $this->launch();
        try {
            app(ExperimentAnalysis::class)->finalize($e['id'], $this->admin);
            $this->fail('Expected maturity guard.');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->status);
        }
        app(BannerExperiments::class)->stop($this->campaign, $this->owner->id);
        $this->travel(32)->days();
        $this->expectException(ApiException::class);
        app(ExperimentAnalysis::class)->finalize($e['id'], $this->admin);
    }

    public function test_changed_proof_blocks_launch(): void
    {
        $this->allowSyntheticProof();
        app(ExperimentAnalysis::class)->register($this->campaign, $this->input, $this->admin);
        $proof = Mockery::mock(ExperimentMeasurementProof::class);
        $proof->shouldReceive('collect')->andReturn([['evidence' => 'changed']]);
        $this->app->instance(ExperimentMeasurementProof::class, $proof);
        $this->app->forgetInstance(ExperimentAnalysis::class);
        $this->expectException(ApiException::class);
        app(BannerExperiments::class)->start($this->campaign, 1, 2, 5000, $this->owner->id);
    }

    public function test_sample_ratio_mismatch_cannot_be_finalized(): void
    {
        $e = $this->launch();
        $this->cohort($e['id'], 100, 300);
        $this->travel(32)->days();
        $this->expectException(ApiException::class);
        app(ExperimentAnalysis::class)->finalize($e['id'], $this->admin);
    }

    public function test_revoked_consent_blocks_readout_instead_of_counting_a_non_conversion(): void
    {
        $e = $this->launch();
        $this->cohort($e['id'], 1, 1);
        DB::table('banner_assignments')->update(['revoked_at' => now()]);
        $this->travel(32)->days();
        $this->expectException(ApiException::class);
        app(ExperimentAnalysis::class)->finalize($e['id'], $this->admin);
    }

    public function test_inadequate_study_gets_one_immutable_non_winner_readout(): void
    {
        $e = $this->launch();
        $this->cohort($e['id'], 1, 1);
        $this->travel(32)->days();
        $service = app(ExperimentAnalysis::class);
        $readout = $service->finalize($e['id'], $this->admin);
        $this->assertSame('insufficient_data', $readout->result['status']);
        $this->assertNull($readout->result['effect']);
        $this->assertNull($readout->result['winner']);
        $this->assertSame($readout->id, $service->finalize($e['id'], $this->admin)->id);
        $this->assertDatabaseCount('banner_experiment_readouts', 1);
        $this->assertSame($readout->public_id, $service->report((object) $e)['readout']);
        $this->expectException(\LogicException::class);
        $readout->forceFill(['result' => ['winner' => 'variant']])->save();
    }

    public function test_missed_fixed_readout_window_is_rejected(): void
    {
        $e = $this->launch();
        $this->travel(34)->days();
        $this->expectException(ApiException::class);
        app(ExperimentAnalysis::class)->finalize($e['id'], $this->admin);
    }

    public function test_native_measurement_proof_checks_context_artifact_and_current_credentials(): void
    {
        Storage::fake('local');
        config(['qa.release_sha' => str_repeat('a', 40)]);
        $connection = ExperimentStudyFixture::connection($this->shop, $this->admin);
        $evidence = ExperimentStudyFixture::proof($this->shop, $this->admin, $connection);
        $proofs = app(ExperimentMeasurementProof::class);
        $snapshot = $proofs->collect($this->campaign, [$evidence]);
        $this->assertSame($connection->id, $snapshot[0]['connection_id']);
        $this->assertCount(2, $snapshot[0]['source_orders']);
        $connection->forceFill(['credential_generation' => 2])->save();
        $this->expectException(ApiException::class);
        $proofs->collect($this->campaign, [$evidence]);
    }

    public function test_real_validator_and_native_sources_finalize_a_frozen_captured_purchase_winner(): void
    {
        Storage::fake('local');
        config(['qa.release_sha' => str_repeat('a', 40)]);
        $connection = ExperimentStudyFixture::connection($this->shop, $this->admin);
        $proof = ExperimentStudyFixture::proof($this->shop, $this->admin, $connection);
        $input = $this->input;
        $input['measurement_evidence_ids'] = [$proof];
        app(ExperimentAnalysis::class)->register($this->campaign, $input, $this->admin);
        app(BannerExperiments::class)->start($this->campaign, 1, 2, 5000, $this->owner->id);
        $experiment = DB::table('banner_experiments')->where('campaign_id', $this->campaign->id)->sole();
        $this->cohort($experiment->id, 1000, 1000);
        $start = now()->toImmutable();
        $this->travel(32)->days();
        $closing = ExperimentStudyFixture::proof($this->shop, $this->admin, $connection);
        $run = IntegrationSyncRun::create(['connection_id' => $connection->id, 'credential_generation' => 1,
            'verified_domain' => $this->shop->verified_domain, 'provider_store_ref' => $connection->external_shop_ref, 'request_key' => (string) Str::uuid(),
            'since_at' => $start->utc()->format('Y-m-d\TH:i:s\Z'), 'until_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'status' => 'completed', 'discovery_complete' => true]);
        $i = 0;
        foreach (['control' => 100, 'variant' => 200] as $arm => $count) {
            foreach (DB::table('banner_assignments')->where('experiment_id', $experiment->id)->where('arm', $arm)->limit($count)->get() as $a) {
                $order = ExperimentStudyFixture::order($this->shop, $connection, $a->id, $a->token_hash, (string) (100000 + $i++), $start->addHour(), $run->id);
                // The registered primary endpoint measures captures, including later full refunds.
                $order->forceFill(['payment_status' => 'refunded', 'refunded_minor' => 1000])->save();
            }
        }
        $native = WebhookEvent::where('connection_id', $connection->id)->where('result->native_external_order_id', '100000')->sole();
        $savedResult = $native->result;
        $withoutToken = $savedResult;
        unset($withoutToken['native_assignment_token_hash']);
        $native->forceFill(['result' => $withoutToken])->save();
        try {
            app(ExperimentAnalysis::class)->finalize($experiment->id, $this->admin, [$closing]);
            $this->fail('A paid outcome with missing native token proof cannot be a non-conversion.');
        } catch (ApiException $e) {
            $this->assertSame('experiment_outcome_unverified', $e->errorCode);
        }
        $native->forceFill(['result' => $savedResult])->save();
        $readout = app(ExperimentAnalysis::class)->finalize($experiment->id, $this->admin, [$closing]);
        $this->assertSame('evaluated', $readout->result['status']);
        $this->assertSame('variant', $readout->result['winner']);
        $this->assertEqualsWithDelta(0.1, $readout->result['effect'], 0.0000001);
        $this->assertSame(100, $readout->result['arms']['control']['converted']);
        $this->assertSame(200, $readout->result['arms']['variant']['converted']);
        $this->assertSame($readout->id, app(ExperimentAnalysis::class)->finalize($experiment->id, $this->admin, [$closing])->id);
        $report = app(BannerExperiments::class)->report($this->campaign, $experiment->id);
        $this->assertEqualsWithDelta(0.1, $report['causal_uplift'], 0.0000001);
        $this->assertFalse($report['winner_evaluation']['automatic_rollout']);
    }

    public function test_real_measurement_artifacts_reject_mocked_unapproved_wrong_build_and_expired_proof(): void
    {
        Storage::fake('local');
        config(['qa.release_sha' => str_repeat('a', 40)]);
        $connection = ExperimentStudyFixture::connection($this->shop, $this->admin);
        $id = ExperimentStudyFixture::proof($this->shop, $this->admin, $connection);
        $evidence = QaEvidence::where('public_id', $id)->sole();
        $result = QaTestResult::findOrFail($evidence->result_id);
        $case = QaTestCase::findOrFail($result->test_case_id);
        $run = QaTestRun::findOrFail($result->run_id);
        foreach (['mocked', 'unapproved', 'wrong_build', 'expired', 'stale'] as $scenario) {
            $result->forceFill(['mocked' => $scenario === 'mocked'])->save();
            $case->forceFill(['status' => $scenario === 'unapproved' ? 'draft' : 'approved'])->save();
            $run->forceFill(['git_sha' => str_repeat($scenario === 'wrong_build' ? 'b' : 'a', 40),
                'started_at' => $scenario === 'stale' ? now()->subDays(8) : now()->subMinutes(5)])->save();
            $evidence->forceFill(['retain_until' => $scenario === 'expired' ? now()->subDay() : now()->addYear()])->save();
            try {
                app(ExperimentMeasurementProof::class)->collect($this->campaign, [$id]);
                $this->fail('Invalid measurement accepted: '.$scenario);
            } catch (ApiException $e) {
                $this->assertSame('experiment_measurement_unverified', $e->errorCode, $scenario);
            }
        }
    }

    public function test_real_sources_require_reconciliation_before_any_readout(): void
    {
        Storage::fake('local');
        config(['qa.release_sha' => str_repeat('a', 40)]);
        $connection = ExperimentStudyFixture::connection($this->shop, $this->admin);
        $proof = ExperimentStudyFixture::proof($this->shop, $this->admin, $connection);
        app(ExperimentAnalysis::class)->register($this->campaign, array_replace($this->input, ['measurement_evidence_ids' => [$proof]]), $this->admin);
        app(BannerExperiments::class)->start($this->campaign, 1, 2, 5000, $this->owner->id);
        $experiment = DB::table('banner_experiments')->where('campaign_id', $this->campaign->id)->sole();
        $this->travel(32)->days();
        $closing = ExperimentStudyFixture::proof($this->shop, $this->admin, $connection);
        try {
            app(ExperimentAnalysis::class)->finalize($experiment->id, $this->admin, [$closing]);
            $this->fail('Missing native source reconciliation accepted.');
        } catch (ApiException $e) {
            $this->assertSame('experiment_reconciliation_incomplete', $e->errorCode);
        }
        $this->assertDatabaseCount('banner_experiment_readouts', 0);
    }
}
