<?php

namespace App\Integrations;

use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\PagedOrderReader;
use App\Models\IntegrationOrderRecovery;
use App\Models\IntegrationSyncRun;
use App\Models\WebshopConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class BulkSynchronization
{
    public function __construct(private ConnectorRegistry $registry, private OrderRecovery $recovery) {}

    public function request(WebshopConnection $model, CarbonImmutable $since, CarbonImmutable $until, string $key): IntegrationSyncRun
    {
        if (! Str::isUuid($key) || $since->greaterThanOrEqualTo($until) || $since->diffInDays($until) > 90 || $until->greaterThan(now()->addMinute())) {
            throw new \InvalidArgumentException('Use a request UUID and a past UTC window no larger than90days.');
        }

        return DB::transaction(function () use ($model, $since, $until, $key) {
            $c = WebshopConnection::with('webshop')->whereKey($model->id)->lockForUpdate()->firstOrFail();
            if ($c->status === ConnectionStatus::Disconnected || ! $c->webshop->isOwnershipVerified() || $c->external_shop_ref === null) {
                throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
            }
            if (! $this->registry->for($c->platform) instanceof PagedOrderReader) {
                throw ApiException::conflict('This platform cannot be connected yet.', 'platform_not_available');
            }
            $s = $since->utc()->format('Y-m-d\TH:i:s\Z');
            $u = $until->utc()->format('Y-m-d\TH:i:s\Z');
            $r = IntegrationSyncRun::where('connection_id', $c->id)->where('credential_generation', $c->credential_generation)->where('request_key', strtolower($key))->first();
            if ($r !== null) {
                if ($r->since_at !== $s || $r->until_at !== $u) {
                    throw ApiException::conflict('The recovery request identity is already bound to another order.', 'recovery_request_conflict');
                }

                return $r;
            }

            return IntegrationSyncRun::create(['connection_id' => $c->id, 'credential_generation' => $c->credential_generation, 'verified_domain' => strtolower((string) $c->webshop->verified_domain), 'provider_store_ref' => $c->external_shop_ref, 'request_key' => strtolower($key), 'since_at' => $s, 'until_at' => $u]);
        }
        );
    }

    public function run(int $id): void
    {
        $candidate = IntegrationSyncRun::find($id);
        if ($candidate === null) {
            return;
        }
        $claim = DB::transaction(function () use ($candidate) {
            $c = WebshopConnection::with('webshop')->whereKey($candidate->connection_id)->lockForUpdate()->firstOrFail();
            $r = IntegrationSyncRun::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (in_array($r->status, ['completed', 'failed', 'superseded'], true)) {
                return null;
            }
            if (! $this->matches($r, $c)) {
                $r->forceFill(['status' => 'superseded', 'last_error_code' => 'connection_changed'])->save();

                return null;
            }
            if ($r->discovery_complete) {
                $ops = IntegrationOrderRecovery::where('bulk_run_id', $r->id);
                if ((clone $ops)->whereIn('status', ['failed', 'superseded'])->exists()) {
                    $r->forceFill(['status' => 'failed', 'last_error_code' => 'order_recovery_incomplete'])->save();
                } elseif (! (clone $ops)->where('status', '!=', 'succeeded')->exists()) {
                    $r->forceFill(['status' => 'completed'])->save();
                }

                return null;
            }
            if ($r->status === 'reading' && $r->leased_until?->isFuture()) {
                return null;
            }
            if ($r->attempts >= 3 || $r->pages >= 10000) {
                $r->forceFill(['status' => 'failed', 'last_error_code' => 'provider_pagination_limit'])->save();

                return null;
            }
            $r->forceFill(['status' => 'reading', 'lease_token' => (string) Str::uuid(), 'leased_until' => now()->addSeconds(60), 'attempts' => $r->attempts + 1])->save();

            return [$c, $r];
        }
        );
        if ($claim === null) {
            return;
        }
        [$snapshot,$claimed] = $claim;
        try {
            $connector = $this->registry->for($snapshot->platform);
            if (! $connector instanceof PagedOrderReader) {
                throw ApiException::conflict('This platform cannot be connected yet.', 'platform_not_available');
            }
            $page = $connector->discoverOrders($snapshot, CarbonImmutable::parse($claimed->since_at), CarbonImmutable::parse($claimed->until_at), $claimed->cursor);
            if (count($page['ids']) > 100 || ($page['next'] !== null && strlen($page['next']) > 2048)) {
                throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
            }
            DB::transaction(function () use ($snapshot, $claimed, $page) {
                $c = WebshopConnection::with('webshop')->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                $r = IntegrationSyncRun::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
                if ($r->lease_token !== $claimed->lease_token || $r->status !== 'reading' || $r->leased_until === null || $r->leased_until->isPast()) {
                    return;
                }
                if (! $this->matches($r, $c) || ! $snapshot->hasSameCredentialAuthority($c)) {
                    $r->forceFill(['status' => 'superseded', 'last_error_code' => 'connection_changed'])->save();

                    return;
                }
                $hash = hash('sha256', $r->cursor ?? 'FIRST');
                if ($page['next'] !== null && ($page['next'] === $r->cursor || DB::table('integration_sync_pages')->where('run_id', $r->id)->where('cursor_hash', hash('sha256', $page['next']))->exists())) {
                    throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
                }
                foreach (array_unique($page['ids']) as $order) {
                    $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'shopbonus:'.$r->public_id.':'.$order)->toString();
                    $this->recovery->request($c, $order, $key, 'reconciliation', $r->id);
                }
                DB::table('integration_sync_pages')->insert(['run_id' => $r->id, 'cursor_hash' => $hash, 'order_ids' => json_encode($page['ids'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
                $r->forceFill(['cursor' => $page['next'], 'pages' => $r->pages + 1, 'discovery_complete' => $page['next'] === null, 'status' => $page['next'] === null ? 'waiting' : 'pending', 'lease_token' => null, 'leased_until' => null, 'attempts' => 0, 'last_error_code' => null])->save();
            }
            );
        } catch (ApiException $e) {
            $this->fail($claimed, $e->errorCode);
        } catch (\Throwable $e) {
            $this->fail($claimed, 'provider_sync_failed');
            throw $e;
        }
    }

    private function matches(IntegrationSyncRun $r, WebshopConnection $c): bool
    {
        return $c->status !== ConnectionStatus::Disconnected && $r->credential_generation === $c->credential_generation && $r->provider_store_ref === $c->external_shop_ref && $c->webshop->isOwnershipVerified() && $r->verified_domain === strtolower((string) $c->webshop->verified_domain);
    }

    private function fail(IntegrationSyncRun $claimed, string $code): void
    {
        DB::transaction(function () use ($claimed, $code) {
            $c = WebshopConnection::with('webshop')->whereKey($claimed->connection_id)->lockForUpdate()->firstOrFail();
            $r = IntegrationSyncRun::whereKey($claimed->id)->lockForUpdate()->firstOrFail();
            if ($r->status === 'reading' && $r->lease_token === $claimed->lease_token) {
                $r->forceFill(['status' => $this->matches($r, $c) ? 'failed' : 'superseded', 'last_error_code' => $code, 'lease_token' => null, 'leased_until' => null])->save();
            }
        }
        );
    }
}
