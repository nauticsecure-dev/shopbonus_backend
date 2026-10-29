<?php

namespace App\Integrations\Contracts;

use App\Models\WebshopConnection;

/**
 * Read-only native catalog and coupon pages, already mapped to the
 * connection_products / connection_coupons columns (minus bookkeeping).
 */
interface CatalogReader
{
    /** @return array{items: list<array<string, mixed>>, pages: int} */
    public function catalogPage(WebshopConnection $connection, string $kind, int $page, string $currency): array;

    /** The store's current ISO 4217 currency, as reported natively. */
    public function catalogCurrency(WebshopConnection $connection): string;
}
