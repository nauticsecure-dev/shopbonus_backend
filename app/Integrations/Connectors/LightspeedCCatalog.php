<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;

/** Read-only C-Series product/variant snapshots. Official contracts:
 * https://developers.lightspeedhq.com/ecom/endpoints/product/
 * https://developers.lightspeedhq.com/ecom/endpoints/variant/
 */
final class LightspeedCCatalog
{
    use CatalogValues;

    /** @param array<string,mixed> $product
     * @param  array<string,mixed>|null  $variant  Only supplied when exactly one variant exists.
     * @return array<string,mixed>
     */
    public static function product(array $product, ?array $variant, string $currency): array
    {
        $id = self::nativeId($product['id'] ?? null);
        $name = self::text($product['title'] ?? null, 255);
        if ($id === null || $name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }
        $price = self::minor($variant['priceIncl'] ?? null, $currency);
        $oldPrice = self::positiveMinor($variant['oldPriceIncl'] ?? null, $currency);
        $stock = $variant['stockLevel'] ?? null;
        $tracked = in_array($variant['stockTracking'] ?? null, ['enabled', 'indicator'], true);

        return self::productRow([
            'external_id' => $id,
            'name' => $name,
            'description' => self::text($product['content'] ?? $product['description'] ?? null, 2000),
            'image_url' => self::url($product['image']['src'] ?? null),
            // The documented URL is a slug; theme/language routing is not inferred.
            'sku' => self::text($variant['sku'] ?? null, 128),
            'price_minor' => $price,
            'regular_price_minor' => $oldPrice !== null && $price !== null && $oldPrice > $price ? $oldPrice : $price,
            'sale_price_minor' => $oldPrice !== null && $price !== null && $oldPrice > $price ? $price : null,
            'currency' => $currency,
            'visibility' => is_bool($product['isVisible'] ?? null) ? ($product['isVisible'] ? 'visible' : 'hidden') : null,
            // No aggregate stock or price is invented for multi-variant products.
            'stock_quantity' => $tracked && is_int($stock) && abs($stock) < 2_000_000_000 ? $stock : null,
            'provider_updated_at' => self::iso($product['updatedAt'] ?? null),
        ]);
    }
}
