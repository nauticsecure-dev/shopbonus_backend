<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;

/**
 * Maps BigCommerce Catalog v3 products and Marketing v2 coupons (official
 * reference: docs.bigcommerce.com/developer/api-reference/rest/admin,
 * catalog/products/get-products and content/marketing/coupons/get-coupons).
 */
final class BigCommerceCatalog
{
    use CatalogValues;

    private const TYPES = ['percentage_discount' => 'percent', 'per_total_discount' => 'fixed_cart', 'per_item_discount' => 'fixed_product', 'free_shipping' => 'free_shipping'];

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function product(array $p, string $currency, string $storeUrl): array
    {
        $id = self::nativeId($p['id'] ?? null);
        $name = self::text($p['name'] ?? null, 255);
        if ($id === null || $name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }
        $tracked = in_array($p['inventory_tracking'] ?? null, ['product', 'variant'], true);
        $level = $p['inventory_level'] ?? null;
        $path = $p['custom_url']['url'] ?? null;
        $sale = self::minor($p['sale_price'] ?? null, $currency);

        return self::productRow([
            'external_id' => $id,
            'sku' => self::text($p['sku'] ?? null, 128),
            'name' => $name,
            'description' => self::text($p['description'] ?? null, 2000),
            // custom_url.url is relative to the verified store URL.
            'url' => is_string($path) && str_starts_with($path, '/') ? self::url(rtrim($storeUrl, '/').$path) : null,
            'image_url' => self::url($p['primary_image']['url_standard'] ?? null),
            'categories' => ($ids = self::idList($p['categories'] ?? null, 20)) === [] ? null : array_map(fn ($i) => ['id' => $i, 'name' => null], $ids),
            'price_minor' => self::minor($p['calculated_price'] ?? null, $currency),
            'regular_price_minor' => self::minor($p['price'] ?? null, $currency),
            'sale_price_minor' => $sale === 0 ? null : $sale,
            'currency' => $currency,
            'product_type' => self::enum($p['type'] ?? null),
            'status' => self::enum($p['availability'] ?? null),
            'visibility' => is_bool($p['is_visible'] ?? null) ? ($p['is_visible'] ? 'visible' : 'hidden') : null,
            'stock_status' => $tracked && is_int($level) ? ($level > 0 ? 'instock' : 'outofstock') : null,
            'stock_quantity' => $tracked && is_int($level) && abs($level) < 2_000_000_000 ? $level : null,
            'provider_updated_at' => self::iso($p['date_modified'] ?? null),
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
        // shipping_discount (amount off shipping) and promotion have no coupon-row equivalent.
        $type = self::TYPES[$c['type'] ?? ''] ?? null;
        $amount = $c['amount'] ?? null;
        $percent = $type === 'percent' ? self::percentString($amount) : null;
        $minor = in_array($type, ['fixed_cart', 'fixed_product'], true) ? self::minor($amount, $currency) : null;
        if ($type === null || ($type === 'percent' && $percent === null) || (in_array($type, ['fixed_cart', 'fixed_product'], true) && $minor === null)) {
            return null;
        }
        $entity = $c['applies_to']['entity'] ?? null;
        $ids = self::idList(array_filter(is_array($c['applies_to']['ids'] ?? null) ? $c['applies_to']['ids'] : [], fn ($v) => $v !== 0));
        $max = self::count($c['max_uses'] ?? null);
        $perCustomer = self::count($c['max_uses_per_customer'] ?? null);

        return self::couponRow([
            'external_id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'amount' => $percent[0] ?? (is_string($amount) ? $amount : '0'),
            'amount_minor' => $minor,
            'percent_bps' => $percent[1] ?? null,
            'currency' => $currency,
            'minimum_spend_minor' => self::positiveMinor($c['min_purchase'] ?? null, $currency),
            // A limit of 0 is stored as "no limit" rather than as an unusable coupon.
            'usage_limit' => $max === 0 ? null : $max,
            'usage_limit_per_customer' => $perCustomer === 0 ? null : $perCustomer,
            'usage_count' => self::count($c['num_uses'] ?? null) ?? 0,
            'free_shipping' => $type === 'free_shipping',
            'scope' => $ids !== [] && in_array($entity, ['products', 'categories'], true) ? [($entity === 'products' ? 'product_ids' : 'category_ids') => $ids] : null,
            'status' => is_bool($c['enabled'] ?? null) ? ($c['enabled'] ? 'active' : 'disabled') : null,
            'expires_at' => self::rfc2822($c['expires'] ?? null),
        ]);
    }

    private static function rfc2822(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || $v === '' || strlen($v) > 64) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat(DATE_RFC2822, $v);

        return $d === false ? null : CarbonImmutable::instance($d)->utc();
    }
}
