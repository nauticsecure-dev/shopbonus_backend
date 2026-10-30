<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;

/**
 * Maps Wix Stores products (Catalog V1 stores-reader/v1/products/query and
 * Catalog V3 stores/v3/products/query) and Wix coupons
 * (stores/v2/coupons/query), from the official dev.wix.com API reference.
 * Fixed-price and buy-x-get-y coupons are skipped.
 */
final class WixCatalog
{
    use CatalogValues;

    private const GUID = '/\A[0-9a-fA-F-]{36}\z/D';

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function productV3(array $p, string $currency): array
    {
        [$id, $name] = self::identity($p);
        $price = $p['actualPriceRange']['minValue']['amount'] ?? null;
        $categories = array_map(fn ($c) => is_array($c) ? ($c['id'] ?? null) : null, is_array($p['directCategoriesInfo']['categories'] ?? null) ? $p['directCategoriesInfo']['categories'] : []);

        return self::productRow([
            'external_id' => $id,
            'sku' => self::text($p['variantsInfo']['variants'][0]['sku'] ?? null, 128),
            'name' => $name,
            'description' => self::text($p['plainDescription'] ?? null, 2000),
            'url' => self::url($p['url']['url'] ?? null),
            'image_url' => self::url($p['media']['main']['image']['url'] ?? null),
            'categories' => ($ids = self::idList($categories, 20)) === [] ? null : array_map(fn ($i) => ['id' => $i, 'name' => null], $ids),
            'price_minor' => ($p['currency'] ?? $currency) === $currency ? self::minor($price, $currency) : null,
            'currency' => $currency,
            'product_type' => is_string($p['productType'] ?? null) ? self::enum(strtolower($p['productType'])) : null,
            'visibility' => is_bool($p['visible'] ?? null) ? ($p['visible'] ? 'visible' : 'hidden') : null,
            'stock_status' => self::stock($p['inventory']['availabilityStatus'] ?? null),
            'provider_updated_at' => self::iso($p['updatedDate'] ?? null),
        ]);
    }

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function productV1(array $p, string $currency): array
    {
        [$id, $name] = self::identity($p);
        $data = is_array($p['priceData'] ?? null) ? $p['priceData'] : [];
        $same = ($data['currency'] ?? null) === $currency;
        $regular = $same ? self::minor($data['price'] ?? null, $currency) : null;
        $discounted = $same ? self::minor($data['discountedPrice'] ?? null, $currency) : null;
        $base = $p['productPageUrl']['base'] ?? null;
        $path = $p['productPageUrl']['path'] ?? null;
        $tracked = ($p['stock']['trackInventory'] ?? false) === true;
        $qty = $p['stock']['quantity'] ?? null;

        return self::productRow([
            'external_id' => $id,
            'sku' => self::text($p['sku'] ?? null, 128),
            'name' => $name,
            'description' => self::text($p['description'] ?? null, 2000),
            'url' => is_string($base) && is_string($path) && str_starts_with($path, '/') ? self::url(rtrim($base, '/').$path) : null,
            'image_url' => self::url($p['media']['mainMedia']['image']['url'] ?? null),
            'categories' => ($ids = self::idList($p['collectionIds'] ?? null, 20)) === [] ? null : array_map(fn ($i) => ['id' => $i, 'name' => null], $ids),
            'price_minor' => $discounted ?? $regular,
            'regular_price_minor' => $regular,
            'sale_price_minor' => $discounted !== null && $discounted !== $regular ? $discounted : null,
            'currency' => $currency,
            'product_type' => self::enum($p['productType'] ?? null),
            'visibility' => is_bool($p['visible'] ?? null) ? ($p['visible'] ? 'visible' : 'hidden') : null,
            'stock_status' => self::stock($p['stock']['inventoryStatus'] ?? null),
            'stock_quantity' => $tracked && is_int($qty) && $qty >= 0 && $qty < 100000 ? $qty : null,
            'provider_updated_at' => self::iso($p['lastUpdated'] ?? null),
        ]);
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>|null
     */
    public static function coupon(array $c, string $currency): ?array
    {
        $id = is_string($c['id'] ?? null) && preg_match(self::GUID, $c['id']) ? strtolower($c['id']) : null;
        $s = $c['specification'] ?? null;
        $code = is_array($s) && is_string($s['code'] ?? null) ? trim($s['code']) : '';
        if ($id === null || $code === '' || mb_strlen($code) > 191) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        $percent = array_key_exists('percentOffRate', $s) ? self::percentString($s['percentOffRate']) : null;
        $minor = array_key_exists('moneyOffAmount', $s) ? self::positiveMinor($s['moneyOffAmount'], $currency) : null;
        [$type, $amount] = match (true) {
            $percent !== null => ['percent', $percent[0]],
            $minor !== null => [($s['limitedToOneItem'] ?? false) === true ? 'fixed_product' : 'fixed_cart', (string) $s['moneyOffAmount']],
            ($s['freeShipping'] ?? false) === true => ['free_shipping', '0'],
            default => [null, null],
        };
        if ($type === null) {
            return null;
        }
        $group = $s['scope']['group'] ?? null;
        $entity = is_array($group) ? self::nativeId($group['entityId'] ?? null) : null;
        $perCustomer = self::count($s['limitPerCustomer'] ?? null);

        return self::couponRow([
            'external_id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'amount' => $amount,
            'amount_minor' => $type === 'percent' ? null : $minor,
            'percent_bps' => $percent[1] ?? null,
            'currency' => $currency,
            'minimum_spend_minor' => array_key_exists('minimumSubtotal', $s) ? self::positiveMinor($s['minimumSubtotal'], $currency) : null,
            'usage_limit' => self::count($s['usageLimit'] ?? null),
            'usage_limit_per_customer' => $perCustomer === 0 ? null : $perCustomer,
            'usage_count' => self::count($c['numberOfUsages'] ?? null) ?? 0,
            'free_shipping' => $type === 'free_shipping',
            'scope' => $entity !== null && in_array($group['name'] ?? null, ['product', 'collection'], true) ? [($group['name'] === 'product' ? 'product_ids' : 'category_ids') => [$entity]] : null,
            'status' => ($c['expired'] ?? false) === true ? 'expired' : (($s['active'] ?? true) === true ? 'active' : 'inactive'),
            'expires_at' => self::epochMs($s['expirationTime'] ?? null),
        ]);
    }

    /** @param array<mixed> $p
     * @return array{0: string, 1: string}
     */
    private static function identity(array $p): array
    {
        $id = is_string($p['id'] ?? null) && preg_match(self::GUID, $p['id']) ? strtolower($p['id']) : null;
        $name = self::text($p['name'] ?? null, 255);
        if ($id === null || $name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }

        return [$id, $name];
    }

    private static function stock(mixed $v): ?string
    {
        return match ($v) {
            'IN_STOCK' => 'instock', 'OUT_OF_STOCK' => 'outofstock', 'PARTIALLY_OUT_OF_STOCK' => 'partial', default => null
        };
    }
}
