<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Maps WooCommerce REST v3 product and coupon objects (official schema:
 * developer.woocommerce.com/docs/apis/rest-api/v3) to catalog snapshot
 * columns. Only documented fields are read; a missing or malformed value
 * stays null instead of being guessed. Coupon customer e-mail restrictions
 * are reduced to a flag so no shopper PII is stored.
 */
final class WooCommerceCatalog
{
    private const DECIMAL = '/\A[0-9]{1,12}(?:\.[0-9]{1,6})?\z/D';

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function product(array $p, string $currency): array
    {
        $name = self::text($p['name'] ?? null, 255);
        if ($name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }
        $categories = [];
        foreach (is_array($p['categories'] ?? null) ? $p['categories'] : [] as $c) {
            if (is_array($c) && (is_int($c['id'] ?? null) || is_string($c['id'] ?? null)) && ($n = self::text($c['name'] ?? null, 120)) !== null) {
                $categories[] = ['id' => (string) $c['id'], 'name' => $n];
            }
        }
        $image = is_array($p['images'][0] ?? null) ? self::url($p['images'][0]['src'] ?? null) : null;
        $stock = $p['stock_quantity'] ?? null;

        return [
            'external_id' => (string) $p['id'],
            'sku' => self::text($p['sku'] ?? null, 128),
            'name' => $name,
            'description' => self::text($p['short_description'] ?? null, 2000) ?? self::text($p['description'] ?? null, 2000),
            'url' => self::url($p['permalink'] ?? null),
            'image_url' => $image,
            'categories' => $categories === [] ? null : array_slice($categories, 0, 20),
            'price_minor' => self::minor($p['price'] ?? null, $currency),
            'regular_price_minor' => self::minor($p['regular_price'] ?? null, $currency),
            'sale_price_minor' => self::minor($p['sale_price'] ?? null, $currency),
            'currency' => $currency,
            'product_type' => self::enum($p['type'] ?? null),
            'status' => self::enum($p['status'] ?? null),
            'visibility' => self::enum($p['catalog_visibility'] ?? null),
            'stock_status' => self::enum($p['stock_status'] ?? null),
            'stock_quantity' => is_int($stock) && abs($stock) < 2_000_000_000 ? $stock : null,
            'provider_updated_at' => self::gmt($p['date_modified_gmt'] ?? null),
        ];
    }

    /**
     * @param  array<mixed>  $c
     * @return array<string, mixed>
     */
    public static function coupon(array $c, string $currency): array
    {
        $code = is_string($c['code'] ?? null) ? trim($c['code']) : '';
        $type = $c['discount_type'] ?? null;
        $amount = $c['amount'] ?? null;
        if ($code === '' || mb_strlen($code) > 191 || ! in_array($type, ['percent', 'fixed_cart', 'fixed_product'], true)
            || ! is_string($amount) || ! preg_match(self::DECIMAL, $amount)) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        $percentBps = null;
        if ($type === 'percent' && preg_match('/\A([0-9]{1,3})(?:\.([0-9]{1,2})0*)?\z/D', $amount, $m) === 1) {
            $percentBps = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        }
        $scope = array_filter([
            'product_ids' => self::ids($c['product_ids'] ?? null),
            'excluded_product_ids' => self::ids($c['excluded_product_ids'] ?? null),
            'category_ids' => self::ids($c['product_categories'] ?? null),
            'excluded_category_ids' => self::ids($c['excluded_product_categories'] ?? null),
        ]);

        return [
            'external_id' => (string) $c['id'],
            'code' => $code,
            'discount_type' => $type,
            'amount' => $amount,
            'amount_minor' => $type === 'percent' ? null : self::minor($amount, $currency),
            'percent_bps' => $percentBps !== null && $percentBps <= 10000 ? $percentBps : null,
            'currency' => $currency,
            'minimum_spend_minor' => self::positiveMinor($c['minimum_amount'] ?? null, $currency),
            'maximum_spend_minor' => self::positiveMinor($c['maximum_amount'] ?? null, $currency),
            'usage_limit' => self::count($c['usage_limit'] ?? null),
            'usage_limit_per_customer' => self::count($c['usage_limit_per_user'] ?? null),
            'usage_count' => self::count($c['usage_count'] ?? null) ?? 0,
            'stackable' => ($c['individual_use'] ?? false) !== true,
            'exclude_sale_items' => ($c['exclude_sale_items'] ?? false) === true,
            'free_shipping' => ($c['free_shipping'] ?? false) === true,
            'customer_restricted' => is_array($c['email_restrictions'] ?? null) && $c['email_restrictions'] !== [],
            'scope' => $scope === [] ? null : $scope,
            'status' => self::enum($c['status'] ?? null),
            'expires_at' => self::gmt($c['date_expires_gmt'] ?? null),
            'provider_updated_at' => self::gmt($c['date_modified_gmt'] ?? null),
        ];
    }

    private static function text(mixed $v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    private static function url(mixed $v): ?string
    {
        if (! is_string($v) || strlen($v) > 2048 || filter_var($v, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($v, PHP_URL_SCHEME)), ['http', 'https'], true) && parse_url($v, PHP_URL_USER) === null ? $v : null;
    }

    private static function minor(mixed $v, string $currency): ?int
    {
        if (! is_string($v) || ! preg_match(self::DECIMAL, $v)) {
            return null;
        }
        try {
            return Money::toMinor($v, $currency);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function positiveMinor(mixed $v, string $currency): ?int
    {
        $m = self::minor($v, $currency);

        return $m === null || $m === 0 ? null : $m;
    }

    private static function count(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 && $v < 4_000_000_000 ? $v : null;
    }

    private static function enum(mixed $v): ?string
    {
        return is_string($v) && preg_match('/\A[a-z_-]{1,32}\z/D', $v) ? $v : null;
    }

    /** @return list<string> */
    private static function ids(mixed $v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $id) {
            if ((is_int($id) && $id > 0) || (is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id))) {
                $out[] = (string) $id;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 500);
    }

    private static function gmt(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\z/D', $v)) {
            return null;
        }
        try {
            $d = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s', $v, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        // Rejects overflowed dates such as 2026-02-31.
        return $d?->format('Y-m-d\TH:i:s') === $v ? $d : null;
    }
}
