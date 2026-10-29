<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;

/**
 * Maps Shopware 6 Admin API product and promotion entities (field names
 * from the core ProductDefinition, PromotionDefinition and
 * PromotionDiscountEntity scope/type constants). Only a promotion with one
 * fixed code and exactly one cart percentage/absolute or full delivery
 * discount becomes a coupon; individual (bulk) codes, set-group discounts
 * and fixed-price discounts are skipped.
 */
final class ShopwareCatalog
{
    use CatalogValues;

    public const SYSTEM_CURRENCY = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';

    private const HEX = '/\A[0-9a-f]{32}\z/D';

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function product(array $p, string $currency): array
    {
        $id = is_string($p['id'] ?? null) && preg_match(self::HEX, $p['id']) ? $p['id'] : null;
        $name = self::text($p['name'] ?? ($p['translated']['name'] ?? null), 255);
        if ($id === null || $name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }
        $gross = null;
        foreach (is_array($p['price'] ?? null) ? $p['price'] : [] as $price) {
            if (is_array($price) && ($price['currencyId'] ?? null) === self::SYSTEM_CURRENCY) {
                $gross = self::minor($price['gross'] ?? null, $currency);
            }
        }
        $categories = [];
        foreach (is_array($p['categories'] ?? null) ? $p['categories'] : [] as $c) {
            if (is_array($c) && is_string($c['id'] ?? null) && preg_match(self::HEX, $c['id'])) {
                $categories[] = ['id' => $c['id'], 'name' => self::text($c['name'] ?? ($c['translated']['name'] ?? null), 120)];
            }
        }
        $stock = is_int($p['availableStock'] ?? null) ? $p['availableStock'] : (is_int($p['stock'] ?? null) ? $p['stock'] : null);

        return self::productRow([
            'external_id' => $id,
            'sku' => self::text($p['productNumber'] ?? null, 128),
            'name' => $name,
            'description' => self::text($p['description'] ?? ($p['translated']['description'] ?? null), 2000),
            'image_url' => self::url($p['cover']['media']['url'] ?? null),
            'categories' => $categories === [] ? null : array_slice($categories, 0, 20),
            'price_minor' => $gross,
            'currency' => $currency,
            'status' => is_bool($p['active'] ?? null) ? ($p['active'] ? 'active' : 'inactive') : null,
            'stock_status' => is_bool($p['available'] ?? null) ? ($p['available'] ? 'instock' : 'outofstock') : null,
            'stock_quantity' => $stock !== null && abs($stock) < 2_000_000_000 ? $stock : null,
            'provider_updated_at' => self::iso($p['updatedAt'] ?? null),
        ]);
    }

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>|null
     */
    public static function coupon(array $p, string $currency): ?array
    {
        $id = is_string($p['id'] ?? null) && preg_match(self::HEX, $p['id']) ? $p['id'] : null;
        if ($id === null) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        $code = is_string($p['code'] ?? null) ? trim($p['code']) : '';
        $discounts = is_array($p['discounts'] ?? null) ? array_values($p['discounts']) : [];
        if (($p['useCodes'] ?? false) !== true || ($p['useIndividualCodes'] ?? false) === true || $code === '' || mb_strlen($code) > 191 || count($discounts) !== 1 || ! is_array($discounts[0])) {
            return null;
        }
        $d = $discounts[0];
        $percent = ($d['type'] ?? null) === 'percentage' ? self::percentString($d['value'] ?? null) : null;
        [$type, $amount, $minor] = match (true) {
            ($d['scope'] ?? null) === 'cart' && $percent !== null => ['percent', $percent[0], null],
            ($d['scope'] ?? null) === 'cart' && ($d['type'] ?? null) === 'absolute' && ($m = self::positiveMinor($d['value'] ?? null, $currency)) !== null => ['fixed_cart', (string) $d['value'], $m],
            ($d['scope'] ?? null) === 'delivery' && ($percent[1] ?? null) === 10000 => ['free_shipping', '0', null],
            default => [null, null, null],
        };
        if ($type === null) {
            return null;
        }
        $global = self::count($p['maxRedemptionsGlobal'] ?? null);
        $perCustomer = self::count($p['maxRedemptionsPerCustomer'] ?? null);

        return self::couponRow([
            'external_id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'amount' => $amount,
            'amount_minor' => $minor,
            'percent_bps' => $type === 'percent' ? $percent[1] : null,
            'currency' => $currency,
            'usage_limit' => $global === 0 ? null : $global,
            'usage_limit_per_customer' => $perCustomer === 0 ? null : $perCustomer,
            'usage_count' => self::count($p['orderCount'] ?? null) ?? 0,
            'stackable' => ($p['exclusive'] ?? false) !== true && ($p['preventCombination'] ?? false) !== true,
            'free_shipping' => $type === 'free_shipping',
            'customer_restricted' => ($p['customerRestriction'] ?? false) === true,
            'scope' => ($d['considerAdvancedRules'] ?? false) === true ? ['restricted' => ['rules']] : null,
            'status' => is_bool($p['active'] ?? null) ? ($p['active'] ? 'active' : 'inactive') : null,
            'expires_at' => self::iso($p['validUntil'] ?? null),
            'provider_updated_at' => self::iso($p['updatedAt'] ?? null),
        ]);
    }
}
