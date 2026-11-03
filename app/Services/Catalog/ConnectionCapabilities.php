<?php

namespace App\Services\Catalog;

use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Enums\WebhookEventStatus;
use App\Integrations\CapabilityMatrix;
use App\Models\CatalogSync;
use App\Models\IntegrationOrderRecovery;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;

/** Feature-specific observations, never proof inherited from a Connected label. */
class ConnectionCapabilities
{
    public const NAMES = CapabilityMatrix::KEYS;

    /** @return list<array<string, mixed>> */
    public function report(WebshopConnection $connection): array
    {
        $declarations = app(CapabilityMatrix::class)->forPlatform($connection->platform);
        $shop = $connection->webshop;
        $prefix = '/api/v1/workspaces/'.$shop->public_id.'/connections/'.$connection->public_id;
        $events = WebhookEvent::where('connection_id', $connection->id)->where('webshop_id', $shop->id)
            ->where('credential_generation', $connection->credential_generation);
        $recoveries = IntegrationOrderRecovery::where('connection_id', $connection->id)->where('webshop_id', $shop->id)
            ->where('credential_generation', $connection->credential_generation)->where('status', 'succeeded')
            ->where('verified_domain', strtolower((string) $shop->verified_domain))->where('provider_store_ref', $connection->external_shop_ref);
        $rows = [];
        foreach (self::NAMES as $feature) {
            $declaration = $declarations[$feature];
            $row = ['capability' => $feature, 'status' => $declaration['state'] === 'UNSUPPORTED' ? 'UNSUPPORTED' : 'LIMITED',
                'reason' => $declaration['reason'] ?? 'No feature-specific observation with the current credentials.',
                'last_verified_at' => null, 'evidence_id' => null, 'source_url' => $declaration['source_url'],
                'error_code' => $declaration['state'] === 'UNSUPPORTED' ? 'capability_unsupported' : 'feature_evidence_missing',
                'evidence_scope' => 'no_current_feature_observation'];
            if ($row['status'] === 'UNSUPPORTED') {
                $rows[] = $row;

                continue;
            }
            $blocked = match (true) {
                $connection->status === ConnectionStatus::Disconnected => 'connection_disconnected',
                $connection->paused_at !== null => 'connection_paused',
                ! $shop->isOwnershipVerified() => 'store_identity_unverified',
                $connection->platform === EcommercePlatform::WooCommerce && $connection->external_shop_ref !== $connection->credential('store_url') => 'provider_store_identity_mismatch',
                default => null,
            };
            if ($blocked !== null) {
                $row['reason'] = $row['error_code'] = $blocked;
                $rows[] = $row;

                continue;
            }
            if (in_array($feature, ['products', 'categories', 'coupons_read'], true)) {
                $sync = CatalogSync::where('connection_id', $connection->id)->where('credential_generation', $connection->credential_generation)
                    ->where('kind', $feature === 'coupons_read' ? 'coupons' : 'products')->latest('id')->first();
                if ($sync !== null) {
                    $ok = $sync->status === 'completed' && $sync->finished_at !== null;
                    $row = array_replace($row, ['status' => $ok ? 'AVAILABLE' : 'LIMITED', 'reason' => $ok ? ($feature === 'categories' ? 'Categories assigned to synced products only.' : null) : 'Latest feature sync is not complete.',
                        'last_verified_at' => $ok ? $sync->finished_at->toIso8601String() : null,
                        'evidence_id' => $sync->public_id, 'source_url' => $prefix.'/catalog-syncs?evidence_id='.$sync->public_id,
                        'error_code' => $ok ? null : ($sync->error_code ?? 'catalog_sync_'.$sync->status), 'evidence_scope' => 'authenticated_catalog_sync_not_installation_acceptance']);
                }
            } elseif (in_array($feature, ['orders', 'payments', 'refunds', 'webhooks'], true)) {
                $query = (clone $events)->where('source_method', $feature === 'webhooks' ? 'webhook' : 'native_api');
                if ($feature !== 'webhooks') {
                    // Unknown store identity cannot establish feature evidence, including legacy null refs.
                    $query->whereIn('id', (clone $recoveries)->whereNotNull('provider_store_ref')->select('webhook_event_id'));
                    $flag = match ($feature) {
                        'payments' => 'native_payment_evidence', 'refunds' => 'native_refund_cash_evidence', default => 'complete'
                    };
                    $query->where('result->ingested', true)->where('result->'.$flag, true);
                    if ($feature === 'refunds') {
                        $query->where('result->native_refund_recovered', true);
                    }
                    if ($declaration['state'] !== 'AVAILABLE') {
                        $query->whereRaw('1 = 0'); // incomplete mappings cannot be promoted by another feature
                    }
                }
                $event = $query->latest('id')->first();
                if ($event !== null) {
                    $ok = $feature === 'webhooks' ? $event->status !== WebhookEventStatus::Failed : $event->status === WebhookEventStatus::Processed;
                    $row = array_replace($row, ['status' => $ok ? 'AVAILABLE' : 'LIMITED', 'reason' => $ok ? null : 'Feature event processing failed.',
                        'last_verified_at' => $ok ? ($event->processed_at ?? $event->received_at)->toIso8601String() : null,
                        'evidence_id' => $event->public_id, 'source_url' => $prefix.'/events?evidence_id='.$event->public_id,
                        'error_code' => $ok ? null : ($event->error_code ?? 'event_processing_failed'), 'evidence_scope' => $feature === 'webhooks' ? 'authenticated_webhook_receipt_not_checkout_acceptance' : 'authenticated_native_feature_observation_not_installation_acceptance']);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
