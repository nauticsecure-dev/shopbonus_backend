<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Synthetic native transport payloads, not provider acceptance evidence. */
class NativeCouponFixture
{
    public const WIX_INSTANCE = '11111111-1111-4111-8111-111111111111';

    public const WIX_SITE = '22222222-2222-4222-8222-222222222222';

    public static function coupon(string $platform, string $code = 'SYNTHETIC-10'): array
    {
        return match ($platform) {
            'shopify' => ['id' => 'gid://shopify/DiscountCodeNode/77', 'codeDiscount' => ['__typename' => 'DiscountCodeBasic',
                'status' => 'ACTIVE', 'startsAt' => now()->subDay()->toIso8601String(), 'endsAt' => now()->addYear()->toIso8601String(), 'updatedAt' => now()->toIso8601String(),
                'usageLimit' => 1, 'appliesOncePerCustomer' => false, 'asyncUsageCount' => 0, 'recurringCycleLimit' => null,
                'codesCount' => ['count' => 1, 'precision' => 'EXACT'], 'codes' => ['nodes' => [['code' => $code]], 'pageInfo' => ['hasNextPage' => false]],
                'context' => ['__typename' => 'DiscountBuyerSelectionAll'], 'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false],
                'minimumRequirement' => ['__typename' => 'DiscountMinimumSubtotal', 'greaterThanOrEqualToSubtotal' => ['amount' => '100.00', 'currencyCode' => 'EUR']],
                'customerGets' => ['value' => ['__typename' => 'DiscountPercentage', 'percentage' => '0.1'], 'items' => ['__typename' => 'AllDiscountItems'], 'appliesOnOneTimePurchase' => true, 'appliesOnSubscription' => false]]],
            'bigcommerce' => ['id' => 77, 'code' => $code, 'type' => 'percentage_discount', 'amount' => '10.0000', 'enabled' => true,
                'min_purchase' => '100.0000', 'max_uses' => 1, 'max_uses_per_customer' => 0, 'num_uses' => 0, 'expires' => now()->addYear()->format(DATE_RFC2822),
                'applies_to' => ['entity' => 'categories', 'ids' => [0]], 'restricted_to' => [], 'shipping_methods' => []],
            'wix' => ['id' => '33333333-3333-4333-8333-333333333333', 'expired' => false, 'numberOfUsages' => 0,
                'specification' => ['code' => strtolower($code), 'active' => true, 'percentOffRate' => 10,
                    'startTime' => (string) now()->subDay()->getTimestampMs(), 'expirationTime' => (string) now()->addYear()->getTimestampMs(),
                    'scope' => ['namespace' => 'stores'], 'minimumSubtotal' => 100, 'usageLimit' => 1]],
            default => ['id' => 77, 'code' => $code, 'discountType' => 'PERCENT', 'discount' => '10', 'status' => 'ACTIVE',
                'launchDate' => now()->subDay()->format('Y-m-d H:i:s O'), 'expirationDate' => now()->addYear()->format('Y-m-d H:i:s O'),
                'totalLimit' => '100', 'usesLimit' => 'SINGLE', 'applicationLimit' => 'UNLIMITED', 'repeatCustomerOnly' => false, 'orderCount' => 0, 'catalogLimit' => [], 'shippingLimit' => []],
        };
    }

    public static function response(Request $request, string $platform, ?array $coupon, string $currency = 'EUR', bool $authorized = true, bool $wrongStore = false): PromiseInterface
    {
        if ($platform === 'wix') {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/apps/v1/instance' => Http::response(['instance' => ['instanceId' => self::WIX_INSTANCE],
                    'site' => ['siteId' => $wrongStore ? self::WIX_INSTANCE : self::WIX_SITE, 'url' => 'https://shop.example.com']]),
                '/site-properties/v4/properties' => Http::response(['properties' => ['paymentCurrency' => $currency]]),
                '/stores/v2/coupons/query' => $authorized
                    ? Http::response(['coupons' => $coupon === null ? [] : [$coupon], 'totalResults' => $coupon === null ? 0 : 1])
                    : Http::response([], 403),
                default => throw new \RuntimeException('Unexpected synthetic Wix operation.'),
            };
        }
        if ($platform === 'shopify') {
            $query = $request['query'];
            $data = match (true) {
                str_contains($query, 'ShopbonusIdentity') => ['shop' => ['id' => 'gid://shopify/Shop/1', 'myshopifyDomain' => $wrongStore ? 'foreign.myshopify.com' : 'synthetic.myshopify.com', 'primaryDomain' => ['host' => 'shop.example.com']],
                    'currentAppInstallation' => ['accessScopes' => $authorized ? [['handle' => 'read_orders'], ['handle' => 'read_discounts']] : []]],
                str_contains($query, 'ShopbonusCurrency') => ['shop' => ['currencyCode' => $currency]],
                str_contains($query, 'ShopbonusCouponByCode') => ['shop' => ['currencyCode' => $currency], 'codeDiscountNodeByCode' => $coupon],
                default => throw new \RuntimeException('Unexpected synthetic GraphQL operation.'),
            };

            return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
        }
        if (str_contains($request->url(), '/v2/store')) {
            return Http::response(['id' => $wrongStore ? 'foreign' : 'abc123', 'secure_url' => 'https://shop.example.com', 'currency' => $currency]);
        }
        if (str_contains($request->url(), '/profile')) {
            return Http::response(['generalInfo' => ['storeId' => $wrongStore ? 999 : 1003, 'storeUrl' => 'https://shop.example.com'],
                'account' => ['suspended' => false, 'availableFeatures' => ['API']], 'formatsAndUnits' => ['currency' => $currency]]);
        }
        if (! $authorized) {
            return Http::response([], 403);
        }
        if ($platform === 'bigcommerce') {
            return Http::response($coupon === null ? [] : [$coupon]);
        }

        return Http::response(['items' => $coupon === null ? [] : [$coupon], 'total' => $coupon === null ? 0 : 1, 'count' => $coupon === null ? 0 : 1, 'offset' => 0]);
    }
}
