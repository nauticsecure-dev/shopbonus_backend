<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Integrations\Http\WooCommerceApi;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\RecoverProviderOrder;
use App\Models\IntegrationOrderRecovery;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderRecovery
{
    public function __construct(private ConnectorRegistry $registry, private AuditLogger $audit) {}

    public function request(WebshopConnection $connection, string $orderId, string $key): IntegrationOrderRecovery
    {
        WooCommerceApi::orderId($orderId);
        if (! Str::isUuid($key)) {
            throw new \InvalidArgumentException('A recovery request UUID is required.');
        }

        return DB::transaction(function () use ($connection, $orderId, $key) {
            $c = WebshopConnection::with('webshop')->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($c->status === ConnectionStatus::Disconnected || ! $c->webshop->isOwnershipVerified()) {
                throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
            }
            if (! ($this->registry->entry($c->platform)['profile']['native_read_api_implemented'] ?? false)) {
                throw ApiException::conflict('This platform cannot be connected yet.', 'platform_not_available');
            }
            if ($c->external_shop_ref === null || $c->external_shop_ref === '') {
                throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
            }
            $otherStore = WebshopOrder::query()->join('webshop_connections as origin', 'origin.id', '=', 'webshop_orders.connection_id')
                ->where('webshop_orders.webshop_id', $c->webshop_id)->where('webshop_orders.platform', $c->platform->value)
                ->where('webshop_orders.connection_id', '!=', $c->id)->where(function ($q) use ($c) {
                    $q->whereNull('origin.external_shop_ref')->orWhere('origin.external_shop_ref', '!=', $c->external_shop_ref);
                })->exists();
            if ($otherStore) {
                throw ApiException::conflict('Changing the provider store after orders exist requires a reviewed migration.', 'provider_store_change_requires_review');
            }
            $r = IntegrationOrderRecovery::where('connection_id', $c->id)->where('credential_generation', $c->credential_generation)
                ->where('request_key', strtolower($key))->first();
            if ($r !== null) {
                if ($r->external_order_id !== $orderId) {
                    throw ApiException::conflict('The recovery request identity is already bound to another order.', 'recovery_request_conflict');
                }

                return $r;
            }
            $r = IntegrationOrderRecovery::create(['connection_id' => $c->id, 'webshop_id' => $c->webshop_id,
                'credential_generation' => $c->credential_generation, 'verified_domain' => strtolower((string) $c->webshop->verified_domain),
                'provider_store_ref' => $c->external_shop_ref, 'external_order_id' => $orderId, 'request_key' => strtolower($key)]);
            app(ConnectionReadiness::class)->refresh($c);
            $this->audit->record('integration.order_recovery_requested', category: AuditCategory::Integration,
                resource: $r, webshopId: $c->webshop_id);
            RecoverProviderOrder::dispatch($r->id);

            return $r;
        });
    }

    public function matches(IntegrationOrderRecovery $r, WebshopConnection $c): bool
    {
        return $c->status !== ConnectionStatus::Disconnected && $r->connection_id === $c->id && $r->webshop_id === $c->webshop_id
            && $r->credential_generation === $c->credential_generation && $c->webshop->isOwnershipVerified()
            && $r->provider_store_ref !== null && $r->provider_store_ref === $c->external_shop_ref
            && ($c->platform !== EcommercePlatform::WooCommerce || $r->provider_store_ref === $c->credential('store_url'))
            && $r->verified_domain === strtolower((string) $c->webshop->verified_domain);
    }

    public function run(int $id): void
    {
        $candidate = IntegrationOrderRecovery::find($id);
        if ($candidate === null) {
            return;
        }
        $claim = DB::transaction(function () use ($candidate) {
            $c = WebshopConnection::with('webshop')->whereKey($candidate->connection_id)->lockForUpdate()->firstOrFail();
            $r = IntegrationOrderRecovery::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (in_array($r->status, ['succeeded', 'failed', 'superseded'], true)) {
                return null;
            }
            if (! $this->matches($r, $c)) {
                $r->forceFill(['status' => 'superseded', 'last_error_code' => 'connection_changed', 'lease_token' => null, 'leased_until' => null])->save();

                return null;
            }
            if ($r->status === 'staged') {
                $event = $r->webhook_event_id === null ? null : WebhookEvent::whereKey($r->webhook_event_id)->lockForUpdate()->first();
                if ($event?->status === WebhookEventStatus::Processed) {
                    $r->forceFill(['status' => 'succeeded', 'completed_at' => now()])->save();
                    app(ConnectionReadiness::class)->refresh($c);
                } elseif ($event === null || $event->payload === null || in_array($event->status, [WebhookEventStatus::Failed, WebhookEventStatus::Ignored], true)) {
                    $r->forceFill(['status' => 'failed', 'last_error_code' => 'event_processing_failed'])->save();
                    app(ConnectionReadiness::class)->refresh($c);
                } else {
                    ProcessWebhookEvent::dispatch($event->id);
                }

                return null;
            }
            if ($r->status === 'reading' && $r->leased_until?->isFuture()) {
                return null;
            }
            if ($r->attempts >= 3) {
                $r->forceFill(['status' => 'failed', 'last_error_code' => 'provider_attempts_exhausted', 'lease_token' => null, 'leased_until' => null])->save();
                app(ConnectionReadiness::class)->refresh($c);

                return null;
            }
            $r->forceFill(['status' => 'reading', 'lease_token' => (string) Str::uuid(), 'leased_until' => now()->addSeconds(60),
                'attempts' => $r->attempts + 1])->save();

            return [$c, $r];
        });
        if ($claim === null) {
            return;
        }
        [$snapshot, $claimed] = $claim;
        try {
            // The only network operation is a fixed authenticated native read, outside row locks.
            $connector = $this->registry->for($snapshot->platform);
            $payload = $connector->fetchOrder($snapshot, $claimed->external_order_id);
            $normalized = $connector->normalizeOrder($payload);
            if ($normalized->externalId !== $claimed->external_order_id) {
                throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
            }
            $body = json_encode($this->canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($body) > 1048576) {
                throw new ApiException('The provider response exceeds the safe read limit.', 502, 'provider_response_too_large');
            }
            DB::transaction(function () use ($snapshot, $claimed, $body) {
                $c = WebshopConnection::with('webshop')->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                $r = IntegrationOrderRecovery::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
                if ($r->status !== 'reading' || $r->lease_token !== $claimed->lease_token || $r->leased_until === null || $r->leased_until->isPast()) {
                    return;
                }
                if (! $this->matches($r, $c) || $snapshot->getRawOriginal('credentials') !== $c->getRawOriginal('credentials')) {
                    $r->forceFill(['status' => 'superseded', 'last_error_code' => 'connection_changed', 'lease_token' => null, 'leased_until' => null])->save();

                    return;
                }
                $c->forceFill(['last_error_code' => null, 'last_error_at' => null])->save();
                $hash = hash('sha256', $body);
                $key = 'native:'.$c->credential_generation.':'.$hash;
                $event = WebhookEvent::where('connection_id', $c->id)->where('dedupe_key', $key)->lockForUpdate()->first();
                if ($event === null) {
                    $event = WebhookEvent::create(['connection_id' => $c->id, 'webshop_id' => $c->webshop_id,
                        'credential_generation' => $c->credential_generation, 'source_method' => 'native_api', 'dedupe_key' => $key,
                        'topic' => 'native.order.read', 'status' => WebhookEventStatus::Verified, 'payload' => $body,
                        'payload_hash' => $hash, 'result' => ['kind' => 'order'], 'received_at' => now()]);
                } else {
                    $event->increment('delivery_count');
                }
                if ($event->status === WebhookEventStatus::Failed && $event->payload !== null) {
                    $event->forceFill(['status' => WebhookEventStatus::Verified, 'error_code' => null])->save();
                }
                $done = $event->status === WebhookEventStatus::Processed;
                $r->forceFill(['webhook_event_id' => $event->id, 'status' => $done ? 'succeeded' : 'staged',
                    'completed_at' => $done ? now() : null, 'lease_token' => null, 'leased_until' => null, 'last_error_code' => null])->save();
                if ($done) {
                    app(ConnectionReadiness::class)->refresh($c);
                } else {
                    ProcessWebhookEvent::dispatch($event->id);
                }
            });
        } catch (ApiException $e) {
            $this->failure($claimed, $e->errorCode);
        } catch (\Throwable $e) {
            $this->failure($claimed, 'provider_recovery_failed');
            throw $e;
        }
    }

    private function failure(IntegrationOrderRecovery $claimed, string $code): void
    {
        DB::transaction(function () use ($claimed, $code) {
            $c = WebshopConnection::with('webshop')->whereKey($claimed->connection_id)->lockForUpdate()->firstOrFail();
            $r = IntegrationOrderRecovery::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'reading' || $r->lease_token !== $claimed->lease_token || $r->leased_until === null || $r->leased_until->isPast()) {
                return;
            }
            $r->forceFill(['status' => $this->matches($r, $c) ? 'failed' : 'superseded', 'last_error_code' => $code,
                'lease_token' => null, 'leased_until' => null])->save();
            if ($this->matches($r, $c)) {
                $c->forceFill(['last_error_code' => $code, 'last_error_at' => now()])->save();
            }
            app(ConnectionReadiness::class)->refresh($c);
            $this->audit->record('integration.order_recovery_failed', category: AuditCategory::Integration, resource: $r,
                webshopId: $c->webshop_id, metadata: ['code' => $code]);
        });
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
