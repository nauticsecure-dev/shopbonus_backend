<?php

namespace App\Integrations\Connectors;

use App\Exceptions\ApiException;

/**
 * Maps Shopify Admin GraphQL 2026-10 Product and code-discount nodes
 * (official reference: shopify.dev/docs/api/admin-graphql/2026-10) to
 * catalog snapshot columns. Only documented fields are read. Discounts
 * that are not one single-code basic or free-shipping discount (BXGY, app
 * discounts, bulk codes) are skipped rather than squeezed into a coupon.
 */
final class ShopifyCatalog
{
    use CatalogValues;

    private const ID = '/\A[1-9][0-9]{0,18}\z/D';

    /**
     * @param  array<mixed>  $p
     * @return array<string, mixed>
     */
    public static function product(array $p, string $currency): array
    {
        $id = self::legacyId($p['legacyResourceId'] ?? null);
        $name = self::text($p['title'] ?? null, 255);
        if ($id === null || ($p['id'] ?? null) !== 'gid://shopify/Product/'.$id || $name === null) {
            throw new ApiException('The provider product data is incomplete.', 502, 'provider_product_invalid');
        }
        $categories = [];
        foreach (is_array($p['collections']['nodes'] ?? null) ? $p['collections']['nodes'] : [] as $c) {
            if (is_array($c) && ($cid = self::legacyId($c['legacyResourceId'] ?? null)) !== null && ($n = self::text($c['title'] ?? null, 120)) !== null) {
                $categories[] = ['id' => $cid, 'name' => $n];
            }
        }
        $price = $p['priceRangeV2']['minVariantPrice'] ?? null;
        $tracks = ($p['tracksInventory'] ?? null) === true;
        $stock = $p['totalInventory'] ?? null;
        $stock = $tracks && is_int($stock) && abs($stock) < 2_000_000_000 ? $stock : null;

        return [
            'external_id' => $id,
            'sku' => self::text($p['variants']['nodes'][0]['sku'] ?? null, 128),
            'name' => $name,
            'description' => self::text($p['description'] ?? null, 2000),
            'url' => self::url($p['onlineStoreUrl'] ?? null),
            'image_url' => self::url($p['featuredMedia']['preview']['image']['url'] ?? null),
            'categories' => $categories === [] ? null : $categories,
            'price_minor' => is_array($price) && ($price['currencyCode'] ?? null) === $currency ? self::minor($price['amount'] ?? null, $currency) : null,
            'regular_price_minor' => null,
            'sale_price_minor' => null,
            'currency' => $currency,
            'product_type' => self::text($p['productType'] ?? null, 32),
            'status' => self::status($p['status'] ?? null),
            'visibility' => null,
            'stock_status' => $stock === null ? null : ($stock > 0 ? 'instock' : 'outofstock'),
            'stock_quantity' => $stock,
            'provider_updated_at' => self::iso($p['updatedAt'] ?? null),
        ];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<string, mixed>|null null when the discount is not one coupon code
     */
    public static function coupon(array $node, string $currency): ?array
    {
        $id = is_string($node['id'] ?? null) && preg_match('~\Agid://shopify/Discount(?:Code)?Node/([1-9][0-9]{0,18})\z~D', $node['id'], $m) ? $m[1] : null;
        $d = $node['discount'] ?? null;
        if ($id === null || ! is_array($d) || ! is_string($d['__typename'] ?? null)) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        $codes = $d['codes']['nodes'] ?? null;
        if (! in_array($d['__typename'], ['DiscountCodeBasic', 'DiscountCodeFreeShipping'], true)
            || ($d['codesCount']['count'] ?? null) !== 1 || ! is_array($codes) || count($codes) !== 1) {
            return null;
        }
        $code = is_string($codes[0]['code'] ?? null) ? trim($codes[0]['code']) : '';
        if ($code === '' || mb_strlen($code) > 191) {
            throw new ApiException('The provider coupon data is incomplete.', 502, 'provider_coupon_invalid');
        }
        $free = $d['__typename'] === 'DiscountCodeFreeShipping';
        $value = $free ? ['__typename' => 'DiscountFreeShipping'] : ($d['customerGets']['value'] ?? null);
        [$type, $amount, $amountMinor, $bps] = match ($value['__typename'] ?? null) {
            'DiscountFreeShipping' => ['free_shipping', '0', null, null],
            'DiscountPercentage' => self::percentage($value['percentage'] ?? null),
            'DiscountAmount' => self::amount($value, $currency),
            default => [null, null, null, null],
        };
        if ($type === null) {
            return null;
        }
        $min = $d['minimumRequirement'] ?? null;
        $minMoney = ($min['__typename'] ?? null) === 'DiscountMinimumSubtotal' ? ($min['greaterThanOrEqualToSubtotal'] ?? null) : null;
        $with = $d['combinesWith'] ?? [];
        $usage = $d['asyncUsageCount'] ?? null;

        return [
            'external_id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'amount' => $amount,
            'amount_minor' => $amountMinor,
            'percent_bps' => $bps,
            'currency' => $currency,
            'minimum_spend_minor' => is_array($minMoney) && ($minMoney['currencyCode'] ?? null) === $currency ? self::positiveMinor($minMoney['amount'] ?? null, $currency) : null,
            'maximum_spend_minor' => null,
            'usage_limit' => self::count($d['usageLimit'] ?? null),
            'usage_limit_per_customer' => ($d['appliesOncePerCustomer'] ?? false) === true ? 1 : null,
            'usage_count' => self::count($usage) ?? 0,
            'stackable' => is_array($with) && in_array(true, [$with['orderDiscounts'] ?? null, $with['productDiscounts'] ?? null, $with['shippingDiscounts'] ?? null], true),
            'exclude_sale_items' => false,
            'free_shipping' => $free,
            'customer_restricted' => ($d['context']['__typename'] ?? null) !== 'DiscountBuyerSelectionAll',
            'scope' => $free ? null : self::scope($d['customerGets']['items'] ?? null),
            'status' => self::status($d['status'] ?? null),
            'expires_at' => self::iso($d['endsAt'] ?? null),
            'provider_updated_at' => self::iso($d['updatedAt'] ?? null),
        ];
    }

    /** @return array{0: string|null, 1: string|null, 2: null, 3: int|null} */
    private static function percentage(mixed $p): array
    {
        // Documented range 0.00–1.00; the pinned reader keeps JSON numbers as exact decimal strings.
        $p = is_int($p) || is_float($p) ? (string) $p : $p;
        if (! is_string($p) || ! preg_match('/\A(?:0(?:\.([0-9]{1,12}))?|1(?:\.0{1,12})?)\z/D', $p, $m) || (float) $p <= 0) {
            return [null, null, null, null];
        }
        if ($p[0] === '1') {
            return ['percent', '100', null, 10000];
        }
        $fraction = str_pad($m[1] ?? '', 12, '0');
        // Percent with up to 10 decimals; basis points only when exactly representable.
        $percent = rtrim(rtrim(ltrim(substr($fraction, 0, 2), '0').'.'.substr($fraction, 2), '0'), '.');
        $percent = str_starts_with($percent, '.') ? '0'.$percent : $percent;

        return ['percent', $percent, null, ltrim(substr($fraction, 4), '0') === '' ? (int) substr($fraction, 0, 4) : null];
    }

    /**
     * @param  array<mixed>  $v
     * @return array{0: string|null, 1: string|null, 2: int|null, 3: null}
     */
    private static function amount(array $v, string $currency): array
    {
        $money = $v['amount'] ?? null;
        if (! is_array($money) || ($money['currencyCode'] ?? null) !== $currency || ! is_string($money['amount'] ?? null)
            || ! preg_match(self::DECIMAL, $money['amount']) || ($minor = self::minor($money['amount'], $currency)) === null) {
            return [null, null, null, null];
        }

        return [($v['appliesOnEachItem'] ?? null) === true ? 'fixed_product' : 'fixed_cart', $money['amount'], $minor, null];
    }

    /** @return array<string, list<string>>|null */
    private static function scope(mixed $items): ?array
    {
        $kind = is_array($items) ? ($items['__typename'] ?? null) : null;
        [$key, $conn] = match ($kind) {
            'DiscountProducts' => ['product_ids', $items['products'] ?? null],
            'DiscountCollections' => ['category_ids', $items['collections'] ?? null],
            default => [null, null],
        };
        if ($key === null || ! is_array($conn) || ! is_array($conn['nodes'] ?? null)) {
            return null;
        }
        $ids = array_values(array_filter(array_map(fn ($n) => is_array($n) ? self::legacyId($n['legacyResourceId'] ?? null) : null, $conn['nodes'])));

        // More than the fixed first 10 targets: the list is explicitly partial.
        return [(($conn['pageInfo']['hasNextPage'] ?? true) === false ? $key : $key.'_partial') => $ids];
    }

    private static function legacyId(mixed $v): ?string
    {
        return (is_int($v) || is_string($v)) && preg_match(self::ID, (string) $v) ? (string) $v : null;
    }

    private static function status(mixed $v): ?string
    {
        return is_string($v) && preg_match('/\A[A-Z_]{1,32}\z/D', $v) ? strtolower($v) : null;
    }
}
