<?php

namespace App\Services\Catalog;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Contracts\CatalogReader;
use App\Models\CatalogSync;
use App\Models\ConnectionCoupon;
use App\Models\ConnectionProduct;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only catalog / native coupon pass (Trello #1 §4, #32 §5). Provider
 * reads happen outside database locks; each page is written under the
 * connection row lock and only while the credential generation that
 * started the pass is still current, so a rotated or disconnected
 * connection never receives data read with old credentials. Items not seen
 * are marked removed only after every page was read.
 */
class CatalogSynchronizer
{
    public function __construct(private readonly ConnectorRegistry $registry, private readonly AuditLogger $audit) {}

    public function supports(WebshopConnection $connection): bool
    {
        return $this->registry->isAvailable($connection->platform) && $this->registry->for($connection->platform) instanceof CatalogReader;
    }

    /** Creates the pass record; the queued job does the reading. */
    public function start(WebshopConnection $connection, string $kind, ?int $actorUserId): CatalogSync
    {
        if (! in_array($kind, CatalogSync::KINDS, true)) {
            throw new \InvalidArgumentException('Unsupported catalog kind.');
        }
        if (! $this->supports($connection)) {
            throw ApiException::conflict('This platform has no native catalog/coupon reader.', 'capability_unsupported', ['capability' => $kind]);
        }
        // Missing read credentials surface from the native transport as provider_credentials_missing.
        if (! $connection->status->receivesEvents()) {
            throw ApiException::conflict('This connection is disconnected. Create a new one.', 'connection_disconnected');
        }
        if (CatalogSync::where('connection_id', $connection->id)->where('kind', $kind)->where('status', 'running')->where('started_at', '>', now()->subMinutes(30))->exists()) {
            throw ApiException::conflict('A sync of this kind is already running.', 'catalog_sync_running');
        }
        $sync = CatalogSync::create(['connection_id' => $connection->id, 'kind' => $kind, 'status' => 'running',
            'credential_generation' => $connection->credential_generation, 'started_at' => now()]);
        $this->audit->record('integration.catalog_sync_requested', category: AuditCategory::Integration, resource: $connection,
            webshopId: $connection->webshop_id, actorUserId: $actorUserId, metadata: ['kind' => $kind, 'sync' => $sync->public_id]);

        return $sync;
    }

    public function run(int $syncId): CatalogSync
    {
        $sync = CatalogSync::findOrFail($syncId);
        if ($sync->status !== 'running') {
            return $sync;
        }
        $lock = Cache::lock('catalog-sync:'.$sync->connection_id.':'.$sync->kind, 900);
        if (! $lock->get()) {
            return $this->finish($sync, 'failed', 'catalog_sync_busy');
        }
        try {
            $connection = WebshopConnection::with('webshop')->findOrFail($sync->connection_id);
            $reader = $this->registry->for($connection->platform);
            if (! $reader instanceof CatalogReader || $connection->credential_generation !== $sync->credential_generation) {
                return $this->finish($sync, 'failed', 'credentials_changed');
            }
            $currency = $reader->catalogCurrency($connection);
            $max = max(1, (int) config('integrations.catalog.max_pages', 50));
            for ($page = 1; $page <= $max; $page++) {
                $result = $reader->catalogPage($connection, $sync->kind, $page, $currency);
                if (! $this->store($sync, $result['items'])) {
                    return $this->finish($sync, 'failed', 'credentials_changed');
                }
                $sync->forceFill(['pages' => $page])->save();
                if (($result['pages'] > 0 && $page >= $result['pages']) || count($result['items']) < 100) {
                    return $this->complete($sync);
                }
            }

            return $this->finish($sync, 'partial', 'catalog_page_limit');
        } catch (ApiException $e) {
            return $this->finish($sync, 'failed', $e->errorCode);
        } catch (Throwable $e) {
            report($e);

            return $this->finish($sync, 'failed', 'catalog_sync_error');
        } finally {
            $lock->release();
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function store(CatalogSync $sync, array $items): bool
    {
        return DB::transaction(function () use ($sync, $items) {
            $c = WebshopConnection::whereKey($sync->connection_id)->lockForUpdate()->first();
            if ($c === null || $c->credential_generation !== $sync->credential_generation || ! $c->status->receivesEvents()) {
                return false;
            }
            $model = $sync->kind === 'products' ? ConnectionProduct::class : ConnectionCoupon::class;
            foreach ($items as $item) {
                $row = $model::firstOrNew(['connection_id' => $c->id, 'external_id' => $item['external_id']]);
                $row->fill($item + ['webshop_id' => $c->webshop_id, 'last_sync_id' => $sync->id, 'removed_at' => null])->save();
            }
            $sync->forceFill(['items' => $sync->items + count($items)])->save();

            return true;
        });
    }

    private function complete(CatalogSync $sync): CatalogSync
    {
        return DB::transaction(function () use ($sync) {
            $c = WebshopConnection::whereKey($sync->connection_id)->lockForUpdate()->first();
            if ($c === null || $c->credential_generation !== $sync->credential_generation) {
                return $this->finish($sync, 'failed', 'credentials_changed');
            }
            $model = $sync->kind === 'products' ? ConnectionProduct::class : ConnectionCoupon::class;
            $removed = $model::where('connection_id', $c->id)->whereNull('removed_at')
                ->where(fn ($q) => $q->whereNull('last_sync_id')->orWhere('last_sync_id', '!=', $sync->id))->update(['removed_at' => now()]);
            $sync->forceFill(['removed' => $removed])->save();

            return $this->finish($sync, 'completed', null);
        });
    }

    private function finish(CatalogSync $sync, string $status, ?string $error): CatalogSync
    {
        $sync->forceFill(['status' => $status, 'error_code' => $error, 'finished_at' => now()])->save();
        $this->audit->recordSafely('integration.catalog_sync_finished', category: AuditCategory::Integration, resource: $sync,
            metadata: ['kind' => $sync->kind, 'status' => $status, 'items' => $sync->items, 'removed' => $sync->removed, 'error' => $error]);

        return $sync;
    }
}
