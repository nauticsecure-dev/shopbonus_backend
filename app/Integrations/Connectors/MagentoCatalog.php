<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;

/**
 * Maps Adobe Commerce / Magento 2.4 REST products and sales rules with
 * their primary coupon (official admin schema 2.4.8: catalog-data-product-
 * interface, sales-rule-data-rule-interface, sales-rule-data-coupon-
 * interface; action and coupon-type values from Magento\SalesRule\Api\Data\
 * RuleInterface). Auto-generated (bulk) codes and buy-x-get-y rules are skipped.
 */
final class MagentoCatalog
{
    use CatalogValues;

    private const ACTIONS = ['by_percent' => 'percent', 'by_fixed' => 'fixed_product', 'cart_fixed' => 'fixed_cart'];

    private const VISIBILITY = [1 => 'not_visible', 2 => 'catalog', 3 => 'search', 4 => 'catalog_search'];

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
        $attributes = [];
        foreach (is_array($p['custom_attributes'] ?? null) ? $p['custom_attributes'] : [] as $a) {
            if (is_array($a) && is_string($a['attribute_code'] ?? null)) {
                $attributes[$a['attribute_code']] = $a['value'] ?? null;
            }
        }
        $links = array_map(fn ($l) => is_array($l) ? ($l['category_id'] ?? null) : null, is_array($p['extension_attributes']['category_links'] ?? null) ? $p['extension_attributes']['category_links'] : []);

        return self::productRow([
            'external_id' => $id,
            'sku' => self::text($p['sku'] ?? null, 128),
            'name' => $name,
            'description' => self::text($attributes['short_description'] ?? null, 2000) ?? self::text($attributes['description'] ?? null, 2000),
            'categories' => ($ids = self::idList($links, 20)) === [] ? null : array_map(fn ($i) => ['id' => $i, 'name' => null], $ids),
            'price_minor' => self::minor($p['price'] ?? null, $currency),
            'currency' => $currency,
            'product_type' => self::enum($p['type_id'] ?? null),
            'status' => match ($p['status'] ?? null) {
                1 => 'enabled', 2 => 'disabled', default => null
            },
            'visibility' => self::VISIBILITY[$p['visibility'] ?? 0] ?? null,
            'provider_updated_at' => self::iso($p['updated_at'] ?? null),
        ]);
    }

    /**
     * @param  array<mixed>  $rule  sales rule
     * @param  array<mixed>|null  $coupon  its primary coupon
     * @return array<string, mixed>|null
     */
    public static function coupon(array $rule, ?array $coupon, string $currency, string $timezone): ?array
    {
        $id = self::nativeId($rule['rule_id'] ?? null);
        if ($id === null) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        $code = is_string($coupon['code'] ?? null) ? trim($coupon['code']) : '';
        $free = in_array((string) ($rule['simple_free_shipping'] ?? '0'), ['1', '2'], true);
        $type = self::ACTIONS[$rule['simple_action'] ?? ''] ?? null;
        $value = $rule['discount_amount'] ?? null;
        $percent = $type === 'percent' ? self::percentString($value) : null;
        $minor = in_array($type, ['fixed_cart', 'fixed_product'], true) ? self::minor($value, $currency) : null;
        if ($code === '' || mb_strlen($code) > 191 || ($rule['coupon_type'] ?? null) !== 'SPECIFIC_COUPON' || ($rule['use_auto_generation'] ?? false) === true || $type === null) {
            return null;
        }
        // A zero-value rule that only grants free shipping is a free-shipping coupon.
        if (($percent === null && $minor === null) || ($minor === 0 || ($percent[1] ?? 1) === 0)) {
            if (! $free) {
                return null;
            }
            [$type, $percent, $minor] = ['free_shipping', null, null];
        }
        $groups = is_array($rule['customer_group_ids'] ?? null) ? $rule['customer_group_ids'] : [];
        $perCustomer = self::count($rule['uses_per_customer'] ?? null);
        $limit = self::count($coupon['usage_limit'] ?? null);
        $to = is_string($rule['to_date'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $rule['to_date']) ? $rule['to_date'] : null;

        return self::couponRow([
            'external_id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'amount' => $percent[0] ?? ($minor !== null ? (string) $value : '0'),
            'amount_minor' => $minor,
            'percent_bps' => $percent[1] ?? null,
            'currency' => $currency,
            // Magento stores 0 for "unlimited" on these limits.
            'usage_limit' => $limit === 0 ? null : $limit,
            'usage_limit_per_customer' => $perCustomer === 0 ? null : $perCustomer,
            'usage_count' => self::count($coupon['times_used'] ?? null) ?? 0,
            'stackable' => ($rule['stop_rules_processing'] ?? true) === false,
            'free_shipping' => $free,
            // Group 0 is NOT LOGGED IN; a rule that excludes guests is customer-restricted.
            'customer_restricted' => ! in_array(0, $groups, true),
            'status' => is_bool($rule['is_active'] ?? null) ? ($rule['is_active'] ? 'active' : 'inactive') : null,
            // to_date is the last valid day in the store timezone.
            'expires_at' => $to === null ? null : self::endOfDay($to, $timezone),
        ]);
    }

    private static function endOfDay(string $date, string $timezone): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($date, in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC')->addDay()->startOfDay()->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
