<?php

namespace App\Integrations\Contracts;

use App\Integrations\Data\CapabilityState;
use App\Models\WebshopConnection;
use Carbon\CarbonImmutable;

/**
 * Optional catalogue / storefront surface on top of the order Connector.
 * Platforms that do not implement this report UNSUPPORTED via CapabilityMatrix.
 */
interface CatalogCapable
{
    /**
     * @return array<string, array{state: CapabilityState, reason: ?string, last_verified_at: ?string}>
     */
    public function getCapabilities(WebshopConnection $connection): array;

    /**
     * @return array{items: list<array<string, mixed>>, cursor: ?string, exhausted: bool}
     */
    public function getProducts(WebshopConnection $connection, ?string $cursor = null): array;

    /**
     * @return list<array{external_category_id: string, name: string, parent_id: ?string}>
     */
    public function getCategories(WebshopConnection $connection): array;

    /**
     * @return array{items: list<array<string, mixed>>, cursor: ?string, exhausted: bool}
     */
    public function getCoupons(WebshopConnection $connection, ?string $cursor = null): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getRefunds(WebshopConnection $connection, string $orderId): array;

    /**
     * @param  array<string, mixed>  $banner
     * @return array{ok: bool, method: string, external_ref: ?string, notice: ?string}
     */
    public function publishBanner(WebshopConnection $connection, array $banner): array;

    /**
     * @return array{ok: bool, method: string, notice: ?string}
     */
    public function unpublishBanner(WebshopConnection $connection, string $externalRef): array;

    /**
     * @return array{healthy: bool, last_success_at: ?CarbonImmutable, lag_seconds: ?int, error: ?string}
     */
    public function getSyncHealth(WebshopConnection $connection): array;
}
