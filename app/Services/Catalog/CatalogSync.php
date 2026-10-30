<?php

namespace App\Services\Catalog;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Contracts\CatalogCapable;
use App\Integrations\Data\CapabilityState;
use App\Models\CatalogCoupon;
use App\Models\CatalogProduct;
use App\Models\CatalogSyncRun;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * Incremental authorised catalogue sync. Never publishes expired or incomplete coupons.
 */
class CatalogSync
{
    public const MAX_PAGES_PER_RUN = 5;

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{run: CatalogSyncRun, imported: int, exhausted: bool, cursor: ?string}
     */
    public function syncProducts(WebshopConnection $connection, ?string $cursor = null): array
    {
        return $this->run($connection, 'products', $cursor, function (CatalogCapable $adapter, ?string $pageCursor) use ($connection) {
            $page = $adapter->getProducts($connection, $pageCursor);
            $n = 0;
            foreach ($page['items'] as $item) {
                if (! is_string($item['external_product_id'] ?? null) || $item['external_product_id'] === '') {
                    continue;
                }
                CatalogProduct::updateOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'external_product_id' => $item['external_product_id'],
                    ],
                    [
                        'webshop_id' => $connection->webshop_id,
                        'platform' => $connection->platform->value,
                        'sku' => $this->str($item['sku'] ?? null, 120),
                        'name' => $this->str($item['name'] ?? null, 500),
                        'description' => isset($item['description']) && is_string($item['description'])
                            ? mb_substr($item['description'], 0, 5000) : null,
                        'product_url' => $this->str($item['product_url'] ?? null, 1000),
                        'image_url' => $this->str($item['image_url'] ?? null, 1000),
                        'category' => $this->str($item['category'] ?? null, 255),
                        'price_minor' => isset($item['price_minor']) && is_int($item['price_minor']) ? $item['price_minor'] : null,
                        'currency' => isset($item['currency']) && is_string($item['currency']) ? strtoupper($item['currency']) : null,
                        'availability' => $this->str($item['availability'] ?? null, 40),
                        'provider_updated_at' => $this->time($item['updated_at'] ?? null),
                        'synced_at' => now(),
                    ]
                );
                $n++;
            }

            return ['imported' => $n, 'cursor' => $page['cursor'] ?? null, 'exhausted' => (bool) ($page['exhausted'] ?? true)];
        });
    }

    /**
     * @return array{run: CatalogSyncRun, imported: int, exhausted: bool, cursor: ?string}
     */
    public function syncCoupons(WebshopConnection $connection, ?string $cursor = null): array
    {
        return $this->run($connection, 'coupons', $cursor, function (CatalogCapable $adapter, ?string $pageCursor) use ($connection) {
            $page = $adapter->getCoupons($connection, $pageCursor);
            $n = 0;
            $now = CarbonImmutable::now();
            foreach ($page['items'] as $item) {
                if (! is_string($item['external_coupon_id'] ?? null) || $item['external_coupon_id'] === '') {
                    continue;
                }
                $end = $this->time($item['end_at'] ?? null);
                $status = is_string($item['status'] ?? null) ? strtolower($item['status']) : '';
                $eligible = $status !== 'expired' && $status !== 'draft'
                    && ($end === null || $end->greaterThan($now))
                    && is_string($item['code'] ?? null) && $item['code'] !== '';

                CatalogCoupon::updateOrCreate(
                    [
                        'connection_id' => $connection->id,
                        'external_coupon_id' => $item['external_coupon_id'],
                    ],
                    [
                        'webshop_id' => $connection->webshop_id,
                        'platform' => $connection->platform->value,
                        'code' => mb_substr((string) $item['code'], 0, 64),
                        'discount_type' => $this->str($item['discount_type'] ?? null, 40),
                        'discount_value' => $this->str($item['discount_value'] ?? null, 40),
                        'minimum_spend' => $this->str($item['minimum_spend'] ?? null, 40),
                        'excluded_categories' => is_array($item['excluded_categories'] ?? null) ? $item['excluded_categories'] : [],
                        'start_at' => $this->time($item['start_at'] ?? null),
                        'end_at' => $end,
                        'usage_limits' => is_array($item['usage_limits'] ?? null) ? $item['usage_limits'] : null,
                        'stacking_rules' => is_array($item['stacking_rules'] ?? null) ? $item['stacking_rules'] : null,
                        'status' => $this->str($item['status'] ?? null, 40),
                        'eligible_public' => $eligible,
                        'synced_at' => now(),
                    ]
                );
                $n++;
            }

            return ['imported' => $n, 'cursor' => $page['cursor'] ?? null, 'exhausted' => (bool) ($page['exhausted'] ?? true)];
        });
    }

    /**
     * @param  callable(CatalogCapable, ?string): array{imported: int, cursor: ?string, exhausted: bool}  $pageFn
     * @return array{run: CatalogSyncRun, imported: int, exhausted: bool, cursor: ?string}
     */
    private function run(WebshopConnection $connection, string $resource, ?string $cursor, callable $pageFn): array
    {
        $connector = $this->registry->for($connection->platform);
        if (! $connector instanceof CatalogCapable) {
            throw ApiException::conflict('Catalogue sync is unsupported for this platform.', 'catalog_unsupported');
        }

        $caps = $connector->getCapabilities($connection);
        $key = $resource === 'products' ? 'products' : 'coupons_read';
        $state = $caps[$key]['state'] ?? CapabilityState::Unsupported;
        if ($state === CapabilityState::Unsupported) {
            throw ApiException::conflict('Catalogue sync is unsupported for this platform.', 'catalog_unsupported');
        }

        $run = CatalogSyncRun::create([
            'webshop_id' => $connection->webshop_id,
            'connection_id' => $connection->id,
            'resource' => $resource,
            'status' => 'running',
            'imported' => 0,
            'cursor' => $cursor,
            'started_at' => now(),
        ]);

        try {
            $imported = 0;
            $pageCursor = $cursor;
            $exhausted = false;
            for ($i = 0; $i < self::MAX_PAGES_PER_RUN; $i++) {
                $page = $pageFn($connector, $pageCursor);
                $imported += $page['imported'];
                $pageCursor = $page['cursor'];
                $exhausted = $page['exhausted'];
                if ($exhausted || $pageCursor === null) {
                    break;
                }
            }

            $run->forceFill([
                'status' => 'succeeded',
                'imported' => $imported,
                'cursor' => $pageCursor,
                'finished_at' => now(),
            ])->save();

            $this->audit->record('catalog.sync_'.$resource, category: AuditCategory::Integration, resource: $run,
                webshopId: $connection->webshop_id, metadata: ['imported' => $imported, 'exhausted' => $exhausted]);

            return ['run' => $run, 'imported' => $imported, 'exhausted' => $exhausted, 'cursor' => $pageCursor];
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ])->save();
            throw $e;
        }
    }

    private function str(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
