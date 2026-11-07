<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;

/**
 * Maps OpenCart 4.1 `product`/`product_description` and `coupon`/`coupon_product`/`coupon_category`
 * rows (upload/system/helper/db_schema.php) as pushed by the ShopBonus extension worker.
 * Coupon semantics follow OpenCart's own checkout code (extension/opencart/catalog/model/total/coupon.php,
 * catalog/model/marketing/coupon.php): type F = fixed amount, P = percentage; `total` = minimum subtotal;
 * `logged` = customer must be logged in; `date_end` stops being valid at its midnight. The worker converts
 * store-local times to ISO 8601 with offset (`date_modified`, `expires_at` = date_end midnight) using its configured timezone.
 */
final class OpenCartCatalog
{
    use CatalogValues;

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function product(array $p, string $currency): array
    {
        $id = $p['product_id'] ?? null;
        $name = self::text($p['name'] ?? null, 255);
        if (! is_int($id) || $id < 1 || $name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }
        $quantity = $p['quantity'] ?? null;

        return self::productRow([
            'external_id' => (string) $id,
            'sku' => self::text($p['sku'] ?? null, 128) ?? self::text($p['model'] ?? null, 128),
            'name' => $name,
            'description' => self::text($p['description'] ?? null, 2000),
            'price_minor' => self::minor($p['price'] ?? null, $currency),
            'currency' => $currency,
            'status' => ($p['status'] ?? null) === 1 ? 'enabled' : (($p['status'] ?? null) === 0 ? 'disabled' : null),
            'stock_quantity' => is_int($quantity) && abs($quantity) < 2_000_000_000 ? $quantity : null,
            'provider_updated_at' => self::iso($p['date_modified'] ?? null),
        ]);
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    public static function coupon(array $c, string $currency): array
    {
        $id = $c['coupon_id'] ?? null;
        $code = is_string($c['code'] ?? null) ? trim($c['code']) : '';
        $type = $c['type'] ?? null;
        $discount = $c['discount'] ?? null;
        if (! is_int($id) || $id < 1 || $code === '' || mb_strlen($code) > 20 || ! in_array($type, ['F', 'P'], true)
            || ! is_string($discount) || ! preg_match(self::DECIMAL, $discount)) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        $percentBps = null;
        if ($type === 'P' && preg_match('/\A([0-9]{1,3})(?:\.([0-9]{1,2})0*)?\z/D', $discount, $m) === 1) {
            $percentBps = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        }
        $scope = array_filter(['product_ids' => self::ids($c['product_ids'] ?? null), 'category_ids' => self::ids($c['category_ids'] ?? null)]);

        return self::couponRow([
            'external_id' => (string) $id,
            'code' => $code,
            'discount_type' => $type === 'F' ? 'fixed_cart' : 'percent',
            'amount' => $discount,
            'amount_minor' => $type === 'F' ? self::minor($discount, $currency) : null,
            'percent_bps' => $percentBps !== null && $percentBps <= 10000 ? $percentBps : null,
            'currency' => $currency,
            'minimum_spend_minor' => self::positiveMinor($c['total'] ?? null, $currency),
            'usage_limit' => self::positiveCount($c['uses_total'] ?? null),
            'usage_limit_per_customer' => self::positiveCount($c['uses_customer'] ?? null),
            'usage_count' => self::count($c['usage_count'] ?? null) ?? 0,
            'free_shipping' => ($c['shipping'] ?? null) === 1,
            'customer_restricted' => ($c['logged'] ?? null) === 1,
            'scope' => $scope === [] ? null : $scope,
            'status' => ($c['status'] ?? null) === 1 ? 'enabled' : 'disabled',
            'expires_at' => self::iso($c['expires_at'] ?? null),
        ]);
    }

    /** @return list<string> */
    private static function ids(mixed $v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $id) {
            if (is_int($id) && $id > 0) {
                $out[] = (string) $id;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 500);
    }

    /** 0 means unlimited in OpenCart. */
    private static function positiveCount(mixed $v): ?int
    {
        $n = self::count($v);

        return $n === null || $n === 0 ? null : $n;
    }

    /** ISO 8601 with an explicit offset, as converted by the worker; anything else stays unknown. */
    private static function iso(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $v)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($v)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
