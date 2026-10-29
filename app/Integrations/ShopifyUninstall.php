<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\WixConnector;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Http\ShopifyApi;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Shopify app/uninstalled and Wix AppRemoved. A replayed uninstall must not revoke a subsequently
 * authorized installation: only a definite native authentication failure counts as revocation.
 */
class ShopifyUninstall
{
    public function process(int $eventId): void
    {
        $event = WebhookEvent::find($eventId);
        if ($event === null || $event->status !== WebhookEventStatus::Verified || ($event->result['kind'] ?? null) !== VerifiedEvent::KIND_DISCONNECT) {
            return;
        }
        $snapshot = WebshopConnection::with('webshop')->findOrFail($event->connection_id);
        $revoked = false;
        $code = 'uninstall_requires_native_revocation_proof';
        // No network under row locks. A valid current token disproves a replayed old uninstall.
        $wix = $snapshot->platform === EcommercePlatform::Wix;
        if (in_array($snapshot->platform, [EcommercePlatform::Shopify, EcommercePlatform::Wix], true) && $snapshot->webshop_id === $event->webshop_id && $snapshot->status !== ConnectionStatus::Disconnected && $snapshot->credential_generation === $event->credential_generation
            && ($snapshot->credential('access_token') !== null || ($wix && $snapshot->credential('wix_app_id') !== null))) {
            try {
                $wix ? app(WixConnector::class)->testConnection($snapshot) : app(ShopifyApi::class)->test($snapshot);
                $code = 'uninstall_current_grant_still_valid';
            } catch (ApiException $e) {
                $revoked = in_array($e->errorCode, ['provider_authentication_failed', 'oauth_reauthorization_required'], true);
                if (! $revoked) {
                    throw $e; // transient errors retry; never equate timeout with revocation
                }
            }
        }
        DB::transaction(function () use ($snapshot, $eventId, $revoked, $code) {
            $c = WebshopConnection::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $e = WebhookEvent::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($e->status !== WebhookEventStatus::Verified) {
                return;
            }
            $bound = $c->platform === $snapshot->platform && $e->webshop_id === $c->webshop_id && $c->status !== ConnectionStatus::Disconnected && $e->credential_generation === $c->credential_generation && $snapshot->hasSameCredentialAuthority($c);
            if ($bound && $revoked) {
                app(ConnectionManager::class)->disconnect($c);
            }
            $e->forceFill(['status' => $bound && $revoked ? WebhookEventStatus::Processed : WebhookEventStatus::Ignored,
                'processed_at' => now(), 'result' => ['kind' => VerifiedEvent::KIND_DISCONNECT, 'credentials_revoked' => $bound && $revoked],
                'error_code' => $bound ? ($revoked ? null : $code) : 'credential_generation_stale'])->save();
            app(AuditLogger::class)->record('integration.uninstall_observed', category: AuditCategory::Integration, resource: $e,
                webshopId: $c->webshop_id, metadata: ['credentials_revoked' => $bound && $revoked]);
        });
    }
}
