<?php

namespace App\Integrations\Contracts;

use App\Models\WebshopConnection;

/**
 * Read-only native catalog and coupon pages, already mapped to the
 * connection_products / connection_coupons columns (minus bookkeeping).
 */
interface CatalogReader
{
    /**
     * One native page. $cursor is null for the first page; `next` is the
     * opaque cursor of the following page, or null after the last page.
     *
     * @return array{items: list<array<string, mixed>>, next: string|null}
     */
    public function catalogPage(WebshopConnection $connection, string $kind, ?string $cursor, string $currency): array;

    /** The store's current ISO 4217 currency, as reported natively. */
    public function catalogCurrency(WebshopConnection $connection): string;
}
