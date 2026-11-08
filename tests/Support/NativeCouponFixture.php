<?php

namespace Tests\Support;

use App\Integrations\Connectors\ShopwareCatalog;
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
            'opencart' => ['coupon_id' => 77, 'code' => $code, 'type' => 'P', 'discount' => '10.0000', 'total' => '100.0000', 'logged' => 0, 'shipping' => 0,
                'uses_total' => 1, 'uses_customer' => 0, 'usage_count' => 0, 'status' => 1, 'product_ids' => [], 'category_ids' => [],
                'starts_at' => now()->subDay()->toIso8601String(), 'expires_at' => now()->addYear()->toIso8601String()],
            'prestashop' => ['id' => '77', 'code' => $code, 'reduction_percent' => '10.00', 'reduction_amount' => '0.00', 'reduction_currency' => '1',
                'minimum_amount' => '100.00', 'minimum_amount_currency' => '1', 'quantity' => '1', 'quantity_per_user' => '1', 'active' => '1',
                'country_restriction' => '0', 'carrier_restriction' => '0', 'group_restriction' => '0', 'product_restriction' => '0', 'shop_restriction' => '0',
                'free_shipping' => '0', 'reduction_exclude_special' => '0', 'partial_use' => '0', 'minimum_amount_shipping' => '0',
                'id_customer' => '0', 'gift_product' => '0', 'reduction_product' => '0',
                'date_from' => now()->subDay()->format('Y-m-d H:i:s'), 'date_to' => now()->addYear()->format('Y-m-d H:i:s')],
            'ccv' => ['id' => 77, 'code' => $code, 'discount' => '10', 'type' => 'Procent', 'minimumprice' => '100.00',
                'begindate' => now()->subDay()->toDateString(), 'enddate' => now()->addYear()->toDateString(), 'onetimeuse' => true,
                'givesfreeshipping' => false, 'productwhitelist' => false, 'products' => [], 'brands' => [], 'suppliers' => [], 'categories' => []],
            'shopware6' => ['id' => str_repeat('a', 32), 'code' => $code, 'useCodes' => true, 'useIndividualCodes' => false,
                'useSetGroups' => false, 'customerRestriction' => false, 'preventCombination' => true, 'exclusive' => true, 'active' => true,
                'maxRedemptionsGlobal' => 1, 'maxRedemptionsPerCustomer' => null, 'orderCount' => 0,
                'validFrom' => now()->subDay()->toIso8601String(), 'validUntil' => now()->addYear()->toIso8601String(),
                'salesChannels' => [['salesChannelId' => str_repeat('b', 32)]], 'personaRules' => [], 'personaCustomers' => [], 'orderRules' => [], 'cartRules' => [], 'setgroups' => [],
                'discounts' => [['scope' => 'cart', 'type' => 'percentage', 'value' => '10', 'considerAdvancedRules' => false, 'discountRules' => [], 'promotionDiscountPrices' => [],
                    'maxValue' => null, 'sorterKey' => null, 'applierKey' => null, 'usageKey' => null, 'pickerKey' => null]]],
            'squarespace' => ['id' => 'discount-77', 'websiteId' => '111111111111111111111111', 'name' => 'Synthetic',
                'criteria' => ['type' => 'CART_TOTAL', 'minimumCartTotal' => ['value' => '100.00', 'currency' => 'EUR']],
                'template' => ['type' => 'PERCENTAGE', 'percentage' => 10], 'trigger' => ['type' => 'CODE', 'promoCode' => $code],
                'status' => 'ACTIVE', 'validFrom' => now()->subDay()->toIso8601String(), 'validTo' => now()->addYear()->toIso8601String(),
                'isLimitedUses' => true, 'maxUsesAllowed' => 1, 'numberOfUses' => 0, 'isOncePerCustomer' => false],
            'magento' => ['rule_id' => 77, 'coupon_type' => 'SPECIFIC_COUPON', 'use_auto_generation' => false,
                'simple_action' => 'by_percent', 'discount_amount' => '10.0000', 'uses_per_coupon' => 1, 'uses_per_customer' => 0,
                'website_ids' => [1], 'customer_group_ids' => [0, 1], 'stop_rules_processing' => true, 'apply_to_shipping' => false,
                'simple_free_shipping' => 'NONE', 'discount_qty' => null, 'discount_step' => 0, 'is_active' => true,
                'from_date' => now()->subDay()->toDateString(), 'to_date' => now()->addYear()->toDateString(),
                'condition' => ['condition_type' => 'Magento\\SalesRule\\Model\\Rule\\Condition\\Combine', 'aggregator_type' => 'all', 'value' => '1',
                    'conditions' => [['condition_type' => 'Magento\\SalesRule\\Model\\Rule\\Condition\\Address', 'attribute_name' => 'base_subtotal', 'operator' => '>=', 'value' => '100.00']]],
                'action_condition' => null],
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

    public static function response(Request $request, string $platform, ?array $coupon, string $currency = 'EUR', bool $authorized = true, bool $wrongStore = false, ?string $moduleSecret = null): PromiseInterface
    {
        if ($platform === 'opencart') {
            require_once base_path('merchant-packages/opencart4/system/library/CouponRead.php');
            if (! \ShopbonusOpenCartCouponRead::authorize($moduleSecret ?? '', (string) $request['code'], $request->header('X-Shopbonus-Time')[0],
                $request->header('X-Shopbonus-Nonce')[0], $request->header('X-Shopbonus-Signature')[0], now()->getTimestamp())) {
                return Http::response([], 403);
            }
            if (! $authorized) {
                return Http::response([], 403);
            }
            $payload = json_encode(['schema' => 'shopbonus.opencart.coupon.v1', 'nonce' => $request->header('X-Shopbonus-Nonce')[0], 'observed_at' => now()->getTimestamp(),
                'store_id' => $wrongStore ? 1 : 0, 'store_url' => 'https://shop.example.com', 'currency' => $currency, 'timezone' => 'Europe/Amsterdam',
                'enabled_totals' => ['opencart:coupon', 'opencart:shipping', 'opencart:sub_total', 'opencart:tax', 'opencart:total'], 'coupon' => $coupon], JSON_THROW_ON_ERROR);

            return Http::response(['payload' => $payload, 'signature' => hash_hmac('sha256', 'shopbonus.opencart.coupon.v1.response|'.$payload, $moduleSecret ?? 'invalid')]);
        }
        if ($platform === 'prestashop') {
            if (! $authorized) {
                return Http::response([], 403);
            }

            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/api/shop_urls' => Http::response(['shop_urls' => [['id_shop' => $wrongStore ? '2' : '1', 'active' => '1', 'domain_ssl' => 'shop.example.com']]]),
                '/api/configurations' => Http::response(['configurations' => [['value' => '1']]]),
                '/api/currencies/1' => Http::response(['currency' => ['iso_code' => $currency]]),
                '/api/order_cart_rules' => Http::response([]),
                '/api/shops' => Http::response(['shops' => [['id' => '1']]]),
                '/api/cart_rules' => Http::response($coupon === null ? [] : ['cart_rules' => [$coupon]]),
                default => throw new \RuntimeException('Unexpected synthetic PrestaShop operation.'),
            };
        }
        if ($platform === 'ccv') {
            if (! $authorized) {
                return Http::response([], 403);
            }

            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/api/rest/v1/webshops/1/' => Http::response(['id' => $wrongStore ? 2 : 1, 'is_multishop_system' => false, 'is_salespos' => false, 'api_root' => 'https://shop.example.com/api/rest/v1']),
                '/api/rest/v1/webshops/1/domains/' => Http::response(['items' => [['ssldomain' => true, 'domainname' => 'shop.example.com']]]),
                '/api/rest/v1/settings/' => Http::response(['currency' => $currency]),
                '/api/rest/v1/discountcoupons/' => Http::response(['start' => 0, 'size' => 100, 'items' => $coupon === null ? [] : [$coupon]]),
                '/api/rest/v1/discountcoupons/77/' => Http::response($coupon),
                default => throw new \RuntimeException('Unexpected synthetic CCV operation.'),
            };
        }
        if ($platform === 'shopware6') {
            if (! $authorized) {
                return Http::response([], 403);
            }

            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/api/oauth/token' => Http::response(['token_type' => 'Bearer', 'access_token' => 'SYNTHETIC_shopware_token', 'expires_in' => 600]),
                '/api/search/sales-channel-domain' => Http::response(['data' => [['salesChannelId' => str_repeat($wrongStore ? 'c' : 'b', 32), 'url' => 'https://shop.example.com']]]),
                '/api/search/currency' => Http::response(['data' => [['id' => ShopwareCatalog::SYSTEM_CURRENCY, 'isoCode' => $currency]], 'total' => 1]),
                '/api/search/promotion' => Http::response(['data' => $coupon === null ? [] : [$coupon], 'total' => $coupon === null ? 0 : 1]),
                default => throw new \RuntimeException('Unexpected synthetic Shopware operation.'),
            };
        }
        if ($platform === 'squarespace') {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/1.0/authorization/website' => Http::response(['id' => $wrongStore ? '222222222222222222222222' : '111111111111111111111111', 'url' => 'https://shop.example.com', 'currency' => $currency]),
                '/v1/commerce/discounts' => $authorized ? Http::response(['discounts' => $coupon === null ? [] : [$coupon], 'hasNextPage' => false]) : Http::response([], 403),
                default => throw new \RuntimeException('Unexpected synthetic Squarespace operation.'),
            };
        }
        if ($platform === 'magento') {
            $detail = ['coupon_id' => 17, 'rule_id' => 77, 'code' => 'SYNTHETIC-10', 'usage_limit' => 1, 'usage_per_customer' => 0, 'times_used' => 0, 'expiration_date' => null];
            if (! $authorized) {
                return Http::response([], 403);
            }

            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/rest/default/V1/store/storeConfigs' => Http::response([['id' => $wrongStore ? 2 : 1, 'code' => 'default', 'secure_base_url' => 'https://shop.example.com/', 'website_id' => 1, 'timezone' => 'Europe/Amsterdam', 'base_currency_code' => $currency, 'default_display_currency_code' => $currency]]),
                '/rest/default/V1/coupons/search' => Http::response(['items' => $coupon === null ? [] : [$detail], 'total_count' => $coupon === null ? 0 : 1]),
                '/rest/default/V1/coupons/17' => Http::response($detail),
                '/rest/default/V1/salesRules/77' => Http::response($coupon),
                '/rest/default/V1/customerGroups/search' => Http::response(['items' => [['id' => 0], ['id' => 1]], 'total_count' => 2]),
                '/rest/default/V1/salesRules/search' => Http::response(['items' => [['rule_id' => 77]], 'total_count' => 1]),
                default => throw new \RuntimeException('Unexpected synthetic Magento operation.'),
            };
        }
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
