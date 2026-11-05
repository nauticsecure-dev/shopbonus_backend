<?php

namespace App\Services\Banners;

use App\Enums\PlatformRole;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Models\BannerCampaign;
use App\Models\IntegrationOrderRecovery;
use App\Models\QaEvidence;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Catalog\ConnectionCapabilities;
use App\Services\Qa\QaRuns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/** Consumes reviewed real execution artifacts, not a configuration boolean claiming successful measurement. */
class ExperimentMeasurementProof
{
    public function fingerprint(): string
    {
        $paths = [];
        foreach (['app/Integrations', 'app/Services/Orders', 'app/Services/Banners', 'app/Listeners'] as $directory) {
            foreach (File::allFiles(base_path($directory)) as $file) {
                if ($file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }
        foreach (['app/Jobs/ProcessWebhookEvent.php', 'app/Support/ExactJson.php', 'app/Support/Money.php', 'config/integrations.php', 'config/banners.php'] as $file) {
            if (is_file(base_path($file))) {
                $paths[] = base_path($file);
            }
        }
        sort($paths);
        $hash = hash_init('sha256');
        foreach (array_unique($paths) as $path) {
            hash_update($hash, substr($path, strlen(base_path())).':'.hash_file('sha256', $path)."\n");
        }

        return hash_final($hash);
    }

    /** @param list<string> $ids
     * @return list<array<string,mixed>>
     */
    public function collect(BannerCampaign $campaign, array $ids, bool $fresh = true): array
    {
        $connections = WebshopConnection::where('webshop_id', $campaign->webshop_id)->where('status', '!=', 'disconnected')->orderBy('id')->get();
        if ($connections->isEmpty() || count($ids) !== $connections->count() || count(array_unique($ids)) !== count($ids)) {
            throw ApiException::conflict('Every active source needs separate reviewed measurement evidence.', 'experiment_measurement_unverified');
        }
        $shop = Webshop::findOrFail($campaign->webshop_id);
        $snapshots = [];
        foreach ($ids as $id) {
            try {
                $evidence = QaEvidence::where('public_id', strtolower($id))->firstOrFail();
                if (! app(QaRuns::class)->evidenceAvailable($evidence)) {
                    throw new \RuntimeException;
                }
                $body = json_decode(app(QaRuns::class)->evidenceBytes($evidence), true, 64, JSON_THROW_ON_ERROR);
                $result = QaTestResult::whereKey($evidence->result_id)->where('run_id', $evidence->run_id)->firstOrFail();
                $run = QaTestRun::findOrFail($evidence->run_id);
                $case = QaTestCase::findOrFail($result->test_case_id);
                $reviewer = User::findOrFail($evidence->uploaded_by);
                if (! is_array($body) || ($body['purpose'] ?? null) !== 'banner_experiment_measurement' || ($body['webshop'] ?? null) !== $shop->public_id
                    || $result->status !== 'pass' || $result->mocked || $result->assertions < 1 || $case->status !== 'approved'
                    || ! in_array($case->source, ['browser', 'postman', 'manual'], true) || $run->environment !== 'staging'
                    || $run->started_at === null || $run->finished_at === null || $run->finished_at->isFuture()
                    || ! $reviewer->isActive() || (! $reviewer->isSuperAdmin() && ! $reviewer->hasPlatformRole(PlatformRole::QaTester))) {
                    throw new \RuntimeException;
                }
                if ($fresh && ($run->started_at->lessThan(now()->subDays(7)) || ! preg_match('/\A[0-9a-f]{40,64}\z/D', (string) config('qa.release_sha')) || $run->git_sha !== config('qa.release_sha'))) {
                    throw new \RuntimeException;
                }
                foreach (['control_verified', 'variant_verified', 'holdout_verified', 'consent_withdrawal_verified', 'no_consent_verified', 'hidden_cart_persistence_verified'] as $flag) {
                    if (($body[$flag] ?? null) !== true) {
                        throw new \RuntimeException;
                    }
                }
                $connection = $connections->firstWhere('public_id', $body['connection'] ?? null);
                if ($connection === null || $connection->paused_at !== null || ! $connection->webshop->isOwnershipVerified()
                    || ($body['credential_generation'] ?? null) !== $connection->credential_generation || ($body['provider_store_ref'] ?? null) !== $connection->external_shop_ref
                    || ($body['verified_domain'] ?? null) !== strtolower((string) $connection->webshop->verified_domain)
                    || ($body['pipeline_hash'] ?? null) !== $this->fingerprint()) {
                    throw new \RuntimeException;
                }
                $capabilities = collect(app(ConnectionCapabilities::class)->report($connection))->keyBy('capability');
                if (($capabilities['orders']['status'] ?? null) !== 'AVAILABLE' || ($capabilities['payments']['status'] ?? null) !== 'AVAILABLE') {
                    throw new \RuntimeException;
                }
                $sourceOrders = [];
                foreach (['control' => 'control_order_id', 'variant' => 'variant_order_id'] as $arm => $field) {
                    $orderId = $body[$field] ?? null;
                    if (! is_string($orderId) || $orderId === '') {
                        throw new \RuntimeException;
                    }
                    $order = WebshopOrder::where('connection_id', $connection->id)->where('webshop_id', $campaign->webshop_id)->where('external_order_id', $orderId)
                        ->where('ingestion_status', 'complete')->whereIn('payment_status', ['paid', 'partially_refunded', 'refunded'])->whereNotNull('paid_at')->firstOrFail();
                    $assignment = DB::table('banner_assignments')->join('banner_experiments', 'banner_experiments.id', '=', 'banner_assignments.experiment_id')
                        ->join('banner_campaigns', 'banner_campaigns.id', '=', 'banner_experiments.campaign_id')->where('banner_campaigns.webshop_id', $campaign->webshop_id)
                        ->where('banner_assignments.id', $order->banner_assignment_id)->where('banner_assignments.arm', $arm)->select('banner_assignments.*')->first();
                    if ($assignment === null || $order->currency !== 'EUR' || $order->paid_at->lessThan(CarbonImmutable::parse($assignment->created_at))
                        || $order->paid_at->greaterThan(CarbonImmutable::parse($assignment->expires_at)) || $assignment->revoked_at !== null
                        || $order->placed_at === null || $order->placed_at->lessThan($run->started_at->subMinute())
                        || $order->paid_at->greaterThan($run->finished_at->addMinute())) {
                        throw new \RuntimeException;
                    }
                    $events = WebhookEvent::where('connection_id', $connection->id)->where('webshop_id', $campaign->webshop_id)->where('credential_generation', $connection->credential_generation)
                        ->where('source_method', 'native_api')->where('status', WebhookEventStatus::Processed)->where('result->native_external_order_id', $orderId)
                        ->where('result->complete', true)->where('result->ingested', true)->where('result->native_payment_evidence', true)
                        ->where('result->native_assignment_token_hash', $assignment->token_hash)
                        ->where('result->native_paid_at_utc', $order->paid_at->utc()->format('Y-m-d H:i:s'))
                        ->where('result->currency', 'EUR')->where('result->amount_minor', '>', 0)
                        ->where('processed_at', '>=', $run->started_at)->where('processed_at', '<=', $run->finished_at->addMinute());
                    $recovery = IntegrationOrderRecovery::where('connection_id', $connection->id)->where('webshop_id', $campaign->webshop_id)
                        ->where('credential_generation', $connection->credential_generation)->where('status', 'succeeded')->where('provider_store_ref', $connection->external_shop_ref)
                        ->where('verified_domain', strtolower((string) $connection->webshop->verified_domain))->whereIn('webhook_event_id', $events->select('id'))->first();
                    if ($recovery === null) {
                        throw new \RuntimeException;
                    }
                    $sourceOrders[] = $order->public_id;
                }
                if (count(array_unique($sourceOrders)) !== 2) {
                    throw new \RuntimeException;
                }
                $snapshots[] = ['connection_id' => $connection->id, 'connection' => $connection->public_id, 'generation' => $connection->credential_generation,
                    'domain' => strtolower((string) $connection->webshop->verified_domain), 'store_ref' => $connection->external_shop_ref,
                    'evidence' => $evidence->public_id, 'sha256' => $evidence->sha256, 'case_version' => $case->version,
                    'pipeline_hash' => $body['pipeline_hash'], 'executed_at' => $run->started_at->toIso8601String(), 'source_orders' => $sourceOrders];
            } catch (Throwable) {
                throw ApiException::conflict('Measurement needs complete current native, consent and approved execution evidence.', 'experiment_measurement_unverified');
            }
        }
        if (count(array_unique(array_column($snapshots, 'connection_id'))) !== $connections->count()) {
            throw ApiException::conflict('Each active connection needs its own measurement proof.', 'experiment_measurement_unverified');
        }
        usort($snapshots, fn ($a, $b) => $a['connection_id'] <=> $b['connection_id']);

        return $snapshots;
    }
}
