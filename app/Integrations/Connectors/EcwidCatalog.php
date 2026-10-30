<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;

/**
 * Maps Ecwid REST v3 products and discount coupons (official reference:
 * docs.ecwid.com/api-reference/rest-api, products/search-products and
 * discounts/discount-coupons/search-discount-coupons) to snapshot columns.
 */
final class EcwidCatalog
{
    use CatalogValues;

    private const TYPES = ['ABS' => ['fixed_cart', false], 'PERCENT' => ['percent', false], 'SHIPPING' => ['free_shipping', true],
        'ABS_AND_SHIPPING' => ['fixed_cart', true], 'PERCENT_AND_SHIPPING' => ['percent', true]];

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function product(array $p, string $currency): array
    {
        $id = self::nativeId($p['id'] ?? null);
        $name = self::text($p['name'] ?? null, 255);
        if ($id === null || $name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }
        $unlimited = ($p['unlimited'] ?? false) === true;
        $quantity = ! $unlimited && is_int($p['quantity'] ?? null) && abs($p['quantity']) < 2_000_000_000 ? $p['quantity'] : null;
        $updated = $p['updateTimestamp'] ?? null;

        return self::productRow([
            'external_id' => $id,
            'sku' => self::text($p['sku'] ?? null, 128),
            'name' => $name,
            'description' => self::text($p['description'] ?? null, 2000),
            'url' => self::url($p['url'] ?? null),
            'image_url' => self::url($p['imageUrl'] ?? null),
            'categories' => ($ids = self::idList($p['categoryIds'] ?? null, 20)) === [] ? null : array_map(fn ($i) => ['id' => $i, 'name' => null], $ids),
            'price_minor' => self::minor($p['price'] ?? null, $currency),
            // compareToPrice is the documented pre-sale price.
            'regular_price_minor' => self::minor($p['compareToPrice'] ?? null, $currency),
            'currency' => $currency,
            'status' => is_bool($p['enabled'] ?? null) ? ($p['enabled'] ? 'enabled' : 'disabled') : null,
            'stock_status' => is_bool($p['inStock'] ?? null) ? ($p['inStock'] ? 'instock' : 'outofstock') : null,
            'stock_quantity' => $quantity,
            'provider_updated_at' => is_int($updated) && $updated > 0 ? CarbonImmutable::createFromTimestamp($updated, 'UTC') : null,
        ]);
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>|null
     */
    public static function coupon(array $c, string $currency): ?array
    {
        $id = self::nativeId($c['id'] ?? null);
        $code = is_string($c['code'] ?? null) ? trim($c['code']) : '';
        if ($id === null || $code === '' || mb_strlen($code) > 191) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        [$type, $shipping] = self::TYPES[$c['discountType'] ?? ''] ?? [null, false];
        if ($type === null) {
            return null;
        }
        $value = $c['discount'] ?? null;
        $percent = $type === 'percent' ? self::percentString($value) : null;
        $minor = $type === 'fixed_cart' ? self::minor($value, $currency) : null;
        if (($type === 'percent' && $percent === null) || ($type === 'fixed_cart' && $minor === null)) {
            return null;
        }
        $uses = $c['usesLimit'] ?? null;
        $scope = array_filter(['product_ids' => self::idList($c['catalogLimit']['products'] ?? null), 'category_ids' => self::idList($c['catalogLimit']['categories'] ?? null)]);

        return self::couponRow([
            'external_id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'amount' => $percent[0] ?? ($minor !== null ? (string) $value : '0'),
            'amount_minor' => $minor,
            'percent_bps' => $percent[1] ?? null,
            'currency' => $currency,
            'minimum_spend_minor' => ($m = self::minor($c['totalLimit'] ?? null, $currency)) ? $m : null,
            'usage_limit' => $uses === 'SINGLE' ? 1 : null,
            'usage_limit_per_customer' => $uses === 'ONCEPERCUSTOMER' ? 1 : null,
            'usage_count' => self::count($c['orderCount'] ?? null) ?? 0,
            'free_shipping' => $shipping,
            'customer_restricted' => in_array($c['applicationLimit'] ?? null, ['NEW_CUSTOMER_ONLY', 'REPEAT_CUSTOMER_ONLY'], true),
            'scope' => $scope === [] ? null : $scope,
            'status' => is_string($c['status'] ?? null) && preg_match('/\A[A-Z_]{1,32}\z/D', $c['status']) ? strtolower($c['status']) : null,
            'expires_at' => self::iso($c['expirationDate'] ?? null),
            'provider_updated_at' => self::iso($c['updateDate'] ?? null),
        ]);
    }
}
