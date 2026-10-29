<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;

/**
 * Maps PrestaShop webservice products and cart_rules (official reference:
 * devdocs.prestashop-project.org/9/webservice/resources). Multilang fields
 * use the first non-empty language value. The webservice reference does not
 * state the tax basis of product `price`; it is stored as returned. Cart
 * rules without a code (automatic rules) and gift-only rules are skipped.
 */
final class PrestaShopCatalog
{
    use CatalogValues;

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function product(array $p, string $currency, string $timezone): array
    {
        $id = self::nativeId($p['id'] ?? null);
        $name = self::text(self::lang($p['name'] ?? null), 255);
        if ($id === null || $name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }
        $categories = array_map(fn ($c) => is_array($c) ? ($c['id'] ?? null) : null, is_array($p['associations']['categories'] ?? null) ? $p['associations']['categories'] : []);
        $qty = is_string($p['quantity'] ?? null) && preg_match('/\A-?[0-9]{1,9}\z/D', $p['quantity']) ? (int) $p['quantity'] : (is_int($p['quantity'] ?? null) ? $p['quantity'] : null);

        return self::productRow([
            'external_id' => $id,
            'sku' => self::text($p['reference'] ?? null, 128),
            'name' => $name,
            'description' => self::text(self::lang($p['description_short'] ?? null), 2000) ?? self::text(self::lang($p['description'] ?? null), 2000),
            'categories' => ($ids = self::idList($categories, 20)) === [] ? null : array_map(fn ($i) => ['id' => $i, 'name' => null], $ids),
            'price_minor' => self::minor($p['price'] ?? null, $currency),
            'currency' => $currency,
            'status' => in_array($p['active'] ?? null, ['1', '0', 1, 0, true, false], true) ? (in_array($p['active'], ['1', 1, true], true) ? 'enabled' : 'disabled') : null,
            'visibility' => self::enum($p['visibility'] ?? null),
            'stock_quantity' => $qty,
            'stock_status' => $qty === null ? null : ($qty > 0 ? 'instock' : 'outofstock'),
            'provider_updated_at' => self::local($p['date_upd'] ?? null, $timezone),
        ]);
    }

    /**
     * @param  array<mixed>  $r
     * @return array<string, mixed>|null
     */
    public static function coupon(array $r, string $currency, string $currencyId, string $timezone): ?array
    {
        $id = self::nativeId($r['id'] ?? null);
        if ($id === null) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        $code = is_string($r['code'] ?? null) ? trim($r['code']) : '';
        if ($code === '' || mb_strlen($code) > 191) {
            return null;
        }
        $flag = fn (string $k) => in_array($r[$k] ?? null, ['1', 1, true], true);
        $percent = self::percentString($r['reduction_percent'] ?? null);
        $amountMinor = (string) ($r['reduction_currency'] ?? '') === $currencyId ? self::positiveMinor($r['reduction_amount'] ?? null, $currency) : null;
        $product = (string) ($r['reduction_product'] ?? '0') !== '0';
        [$type, $amount] = match (true) {
            $percent !== null => ['percent', $percent[0]],
            $amountMinor !== null => [$product ? 'fixed_product' : 'fixed_cart', (string) $r['reduction_amount']],
            $flag('free_shipping') => ['free_shipping', '0'],
            default => [null, null],
        };
        if ($type === null) {
            return null;
        }
        $remaining = self::count(is_string($r['quantity'] ?? null) && ctype_digit($r['quantity']) ? (int) $r['quantity'] : ($r['quantity'] ?? null));
        $perUser = self::count(is_string($r['quantity_per_user'] ?? null) && ctype_digit($r['quantity_per_user']) ? (int) $r['quantity_per_user'] : ($r['quantity_per_user'] ?? null));

        return self::couponRow([
            'external_id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'amount' => $amount,
            'amount_minor' => $type === 'percent' ? null : $amountMinor,
            'percent_bps' => $percent[1] ?? null,
            'currency' => $currency,
            'minimum_spend_minor' => (string) ($r['minimum_amount_currency'] ?? '') === $currencyId ? self::positiveMinor($r['minimum_amount'] ?? null, $currency) : null,
            // `quantity` is the number of uses still available; uses so far are not exposed.
            'usage_limit' => $remaining,
            'usage_limit_per_customer' => $perUser === 0 ? null : $perUser,
            'stackable' => ! $flag('cart_rule_restriction'),
            'exclude_sale_items' => $flag('reduction_exclude_special'),
            'free_shipping' => $flag('free_shipping'),
            'customer_restricted' => (string) ($r['id_customer'] ?? '0') !== '0' || $flag('group_restriction'),
            'scope' => $flag('product_restriction') ? ['restricted' => ['products']] : null,
            'status' => $flag('active') ? 'active' : 'inactive',
            'expires_at' => self::local($r['date_to'] ?? null, $timezone),
            'provider_updated_at' => self::local($r['date_upd'] ?? null, $timezone),
        ]);
    }

    private static function lang(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        foreach ($v as $l) {
            if (is_array($l) && is_string($l['value'] ?? null) && trim($l['value']) !== '') {
                return $l['value'];
            }
        }

        return null;
    }

    private static function local(mixed $v, string $timezone): ?CarbonImmutable
    {
        if (! is_string($v) || ! preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $v) || str_starts_with($v, '0000')) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $v, in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC')?->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
