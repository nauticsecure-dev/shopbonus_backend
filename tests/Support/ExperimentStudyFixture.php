<?php

namespace Tests\Support;

use App\Models\IntegrationOrderRecovery;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Banners\BannerService;
use App\Services\Banners\ExperimentMeasurementProof;
use App\Services\Qa\QaRuns;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicitly synthetic records for checking proof validation. Never independent provider acceptance. */
class ExperimentStudyFixture
{
    public static function connection(Webshop $shop, User $admin): WebshopConnection
    {
        return WebshopConnection::create(['webshop_id' => $shop->id, 'platform' => 'shopify', 'method' => 'webhook_api',
            'status' => 'limited', 'webhook_token' => Str::random(48), 'credential_generation' => 1,
            'external_shop_ref' => 'synthetic.myshopify.com', 'created_by' => $admin->id]);
    }

    public static function proof(Webshop $shop, User $admin, WebshopConnection $connection): string
    {
        $banners = app(BannerService::class);
        $campaign = $banners->create($shop, 'Synthetic calibration', null, BannerFixture::config(), $admin->id);
        $banners->publish($campaign, $admin->id);
        $config = $campaign->draft_config;
        $config['placement']['anchor'] = 'bottom';
        $banners->saveDraft($campaign, $config, 1, $admin->id);
        $banners->publish($campaign, $admin->id);
        $versions = DB::table('banner_versions')->where('campaign_id', $campaign->id)->orderBy('version')->pluck('id');
        $experiment = DB::table('banner_experiments')->insertGetId(['public_id' => strtolower((string) Str::ulid()),
            'campaign_id' => $campaign->id, 'active_campaign_id' => null, 'control_version_id' => $versions[0], 'variant_version_id' => $versions[1],
            'variant_bps' => 5000, 'created_by' => $admin->id, 'started_at' => now()->subMinutes(4), 'stopped_at' => now()->subMinute()]);
        $body = ['purpose' => 'banner_experiment_measurement', 'webshop' => $shop->public_id, 'connection' => $connection->public_id,
            'credential_generation' => 1, 'provider_store_ref' => $connection->external_shop_ref, 'verified_domain' => $shop->verified_domain,
            'pipeline_hash' => app(ExperimentMeasurementProof::class)->fingerprint(), 'control_verified' => true, 'variant_verified' => true,
            'holdout_verified' => true, 'consent_withdrawal_verified' => true, 'no_consent_verified' => true, 'hidden_cart_persistence_verified' => true];
        foreach (['control', 'variant'] as $arm) {
            $tokenHash = hash('sha256', Str::random(32));
            $assignment = DB::table('banner_assignments')->insertGetId(['experiment_id' => $experiment,
                'arm' => $arm, 'visitor_hash' => hash('sha256', Str::random(32)), 'token_hash' => $tokenHash,
                'created_at' => now()->subMinutes(3), 'expires_at' => now()->subMinutes(3)->addDays(30)]);
            $externalId = (string) random_int(1000000000, 9999999999);
            self::order($shop, $connection, $assignment, $tokenHash, $externalId, now()->subMinutes(2));
            $body[$arm.'_order_id'] = $externalId;
        }
        $name = 'measurement_'.Str::random(12);
        $run = app(QaRuns::class)->importJunit('<testsuite><testcase classname="Synthetic" name="'.$name.'" assertions="20" time="300"/></testsuite>',
            ['suite' => 'full', 'environment' => 'staging', 'git_sha' => str_repeat('a', 40), 'source' => 'manual', 'executed_at' => now()->subMinutes(5)->toIso8601String()], $admin->id);
        $result = QaTestResult::where('run_id', $run->id)->sole();
        QaTestCase::findOrFail($result->test_case_id)->forceFill(['status' => 'approved', 'version' => 1, 'approved_by' => $admin->id, 'approved_at' => now()])->save();

        return app(QaRuns::class)->storeEvidence($run, UploadedFile::fake()->createWithContent('synthetic-measurement.json', json_encode($body, JSON_THROW_ON_ERROR)),
            $result, 'SYNTHETIC-REQUEST', $admin->id)->public_id;
    }

    public static function order(Webshop $shop, WebshopConnection $connection, int $assignment, string $tokenHash, string $externalId, CarbonInterface $paidAt, ?int $run = null): WebshopOrder
    {
        $order = WebshopOrder::create(['webshop_id' => $shop->id, 'connection_id' => $connection->id, 'platform' => 'shopify',
            'external_order_id' => $externalId, 'customer_first_name' => 'Synthetic', 'customer_email' => 'synthetic@example.test',
            'amount_minor' => 1000, 'currency' => 'EUR', 'payment_status' => 'paid', 'ingestion_status' => 'complete',
            'placed_at' => $paidAt, 'paid_at' => $paidAt, 'provider_updated_at' => $paidAt, 'banner_assignment_id' => $assignment]);
        $event = WebhookEvent::create(['connection_id' => $connection->id, 'webshop_id' => $shop->id, 'credential_generation' => 1,
            'dedupe_key' => 'synthetic:'.Str::uuid(), 'source_method' => 'native_api', 'status' => 'processed', 'payload_hash' => hash('sha256', 'synthetic'),
            'received_at' => now(), 'processed_at' => now(), 'result' => ['ingested' => true, 'complete' => true,
                'native_external_order_id' => $externalId, 'native_assignment_token_hash' => $tokenHash, 'native_payment_evidence' => true,
                'native_paid_at_utc' => $paidAt->utc()->format('Y-m-d H:i:s'), 'currency' => 'EUR', 'amount_minor' => 1000]]);
        IntegrationOrderRecovery::create(['connection_id' => $connection->id, 'webshop_id' => $shop->id, 'credential_generation' => 1,
            'verified_domain' => $shop->verified_domain, 'provider_store_ref' => $connection->external_shop_ref, 'external_order_id' => $externalId,
            'request_key' => (string) Str::uuid(), 'status' => 'succeeded', 'webhook_event_id' => $event->id, 'completed_at' => now(), 'bulk_run_id' => $run]);

        return $order;
    }
}
