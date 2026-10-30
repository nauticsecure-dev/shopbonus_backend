<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectionManager;
use App\Models\ConnectionCoupon;
use App\Models\ConnectionProduct;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Catalog\CatalogSynchronizer;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One synthetic pass per platform, with response shapes taken from each
 * provider's official reference (see the *Catalog mapper docblocks).
 */
class PlatformCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://fietsen-utrecht.nl';

    private const CHANNEL = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const WIX_INSTANCE = '11111111-1111-4111-8111-111111111111';

    private const WIX_SITE = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function addresses(string $h): array
            {
                return ['93.184.216.34'];
            }

            public function txtRecords(string $h): array
            {
                return [];
            }
        });
    }

    /** @return array<string, array{0: EcommercePlatform, 1: array<string, mixed>, 2: \Closure, 3: array<string, mixed>, 4: array<string, mixed>}> */
    public static function platforms(): array
    {
        return [
            'bigcommerce' => [EcommercePlatform::BigCommerce, ['store_hash' => 'abc123', 'access_token' => 'SYNTHETIC_bc_token', 'client_secret' => 'SYNTHETIC_bc_secret'],
                fn (string $path) => match (true) {
                    str_ends_with($path, '/v2/store') => ['id' => 'abc123', 'secure_url' => self::URL, 'features' => ['multi_storefront_enabled' => false], 'currency' => 'EUR'],
                    str_ends_with($path, '/v3/catalog/products') => ['data' => [['id' => 77, 'name' => 'Stadsfiets', 'sku' => 'SF-1', 'type' => 'physical', 'description' => '<p>Fiets</p>', 'price' => 549.95,
                        'sale_price' => 0, 'calculated_price' => 499.95, 'inventory_tracking' => 'product', 'inventory_level' => 3, 'is_visible' => true, 'availability' => 'available',
                        'categories' => [23], 'custom_url' => ['url' => '/stadsfiets/'], 'primary_image' => ['url_standard' => 'https://cdn11.bigcommerce.com/s-abc123/sf.jpg'],
                        'date_modified' => '2026-10-20T09:00:00+00:00']], 'meta' => ['pagination' => ['total_pages' => 1, 'current_page' => 1]]],
                    str_ends_with($path, '/v2/coupons') => [['id' => 5, 'code' => 'WELKOM10', 'type' => 'percentage_discount', 'amount' => '10.0000', 'min_purchase' => '50.0000', 'enabled' => true,
                        'expires' => 'Thu, 31 Dec 2026 23:00:00 +0000', 'applies_to' => ['entity' => 'categories', 'ids' => [0]], 'num_uses' => 2, 'max_uses' => 0, 'max_uses_per_customer' => 1],
                        ['id' => 6, 'code' => 'VERZEND5', 'type' => 'shipping_discount', 'amount' => '5.0000']],
                },
                ['external_id' => '77', 'price_minor' => 49995, 'regular_price_minor' => 54995, 'url' => self::URL.'/stadsfiets/', 'stock_quantity' => 3],
                ['external_id' => '5', 'discount_type' => 'percent', 'percent_bps' => 1000, 'minimum_spend_minor' => 5000, 'usage_limit' => null, 'scope' => null]],
            'ecwid' => [EcommercePlatform::Ecwid, ['store_id' => '1003', 'access_token' => 'SYNTHETIC_ecwid_token', 'client_secret' => 'SYNTHETIC_ecwid_secret'],
                fn (string $path) => match (true) {
                    str_ends_with($path, '/profile') => ['generalInfo' => ['storeId' => 1003, 'storeUrl' => self::URL], 'account' => ['suspended' => false, 'availableFeatures' => ['API']], 'formatsAndUnits' => ['currency' => 'EUR']],
                    str_ends_with($path, '/products') => ['total' => 1, 'count' => 1, 'offset' => 0, 'limit' => 100, 'items' => [['id' => 501, 'sku' => 'SF-1', 'name' => 'Stadsfiets', 'price' => 499.95,
                        'compareToPrice' => 549.95, 'url' => self::URL.'/Stadsfiets-p501', 'imageUrl' => 'https://images-cdn.ecwid.com/sf.jpg', 'enabled' => true, 'inStock' => true,
                        'quantity' => 3, 'unlimited' => false, 'categoryIds' => [9], 'description' => '<p>Fiets</p>', 'updateTimestamp' => 1792486800]]],
                    str_ends_with($path, '/discount_coupons') => ['total' => 1, 'count' => 1, 'offset' => 0, 'limit' => 100, 'items' => [['id' => 31, 'name' => 'Welkom', 'code' => 'WELKOM10',
                        'discountType' => 'ABS_AND_SHIPPING', 'status' => 'ACTIVE', 'discount' => 10, 'totalLimit' => 50, 'usesLimit' => 'ONCEPERCUSTOMER', 'applicationLimit' => 'NEW_CUSTOMER_ONLY',
                        'orderCount' => 4, 'expirationDate' => '2026-12-31 23:00:00 +0000', 'updateDate' => '2026-10-21 10:00:00 +0000', 'catalogLimit' => ['products' => [501], 'categories' => []]]]],
                },
                ['external_id' => '501', 'price_minor' => 49995, 'regular_price_minor' => 54995, 'stock_status' => 'instock'],
                ['external_id' => '31', 'discount_type' => 'fixed_cart', 'amount_minor' => 1000, 'free_shipping' => true, 'usage_limit_per_customer' => 1, 'customer_restricted' => true, 'scope' => ['product_ids' => ['501']]]],
            'magento' => [EcommercePlatform::Magento, ['store_url' => self::URL, 'access_token' => 'SYNTHETIC_magento_token', 'store_code' => 'default', 'store_id' => '1'],
                fn (string $path) => match (true) {
                    str_ends_with($path, '/store/storeConfigs') => [['id' => 1, 'code' => 'default', 'secure_base_url' => self::URL.'/', 'base_currency_code' => 'EUR', 'timezone' => 'Europe/Amsterdam']],
                    str_ends_with($path, '/products') => ['items' => [['id' => 12, 'sku' => 'SF-1', 'name' => 'Stadsfiets', 'price' => 499.95, 'status' => 1, 'visibility' => 4, 'type_id' => 'simple',
                        'updated_at' => '2026-10-20 09:00:00', 'extension_attributes' => ['category_links' => [['category_id' => '3']]],
                        'custom_attributes' => [['attribute_code' => 'short_description', 'value' => '<p>Fiets</p>']]]], 'total_count' => 1],
                    str_ends_with($path, '/salesRules/search') => ['items' => [
                        ['rule_id' => 8, 'name' => 'Welkom', 'is_active' => true, 'to_date' => '2026-12-31', 'customer_group_ids' => [0, 1], 'simple_action' => 'cart_fixed', 'discount_amount' => 10,
                            'stop_rules_processing' => false, 'coupon_type' => 'SPECIFIC_COUPON', 'use_auto_generation' => false, 'uses_per_customer' => 0, 'simple_free_shipping' => '0'],
                        ['rule_id' => 9, 'name' => 'BXGY', 'is_active' => true, 'simple_action' => 'buy_x_get_y', 'discount_amount' => 1, 'coupon_type' => 'SPECIFIC_COUPON', 'use_auto_generation' => false]], 'total_count' => 2],
                    str_ends_with($path, '/coupons/search') => ['items' => [['coupon_id' => 1, 'rule_id' => 8, 'code' => 'WELKOM10', 'usage_limit' => 100, 'times_used' => 2, 'is_primary' => true],
                        ['coupon_id' => 2, 'rule_id' => 9, 'code' => 'GRATIS', 'usage_limit' => 0, 'times_used' => 0, 'is_primary' => true]], 'total_count' => 2],
                },
                ['external_id' => '12', 'price_minor' => 49995, 'status' => 'enabled', 'visibility' => 'catalog_search'],
                ['external_id' => '8', 'discount_type' => 'fixed_cart', 'amount_minor' => 1000, 'usage_limit' => 100, 'usage_count' => 2, 'stackable' => true, 'customer_restricted' => false]],
            'prestashop' => [EcommercePlatform::PrestaShop, ['store_url' => self::URL, 'webservice_key' => 'SYNTHETIC_presta_key', 'shop_id' => '1', 'timezone' => 'Europe/Amsterdam'],
                fn (string $path) => match (true) {
                    str_ends_with($path, '/api/shop_urls') => ['shop_urls' => [['id_shop' => '1', 'active' => '1', 'domain_ssl' => 'fietsen-utrecht.nl']]],
                    str_ends_with($path, '/api/configurations') => ['configurations' => [['id' => '50', 'name' => 'PS_CURRENCY_DEFAULT', 'value' => '1']]],
                    str_ends_with($path, '/api/currencies/1') => ['currency' => ['id' => 1, 'iso_code' => 'EUR']],
                    str_ends_with($path, '/api/products') => ['products' => [['id' => 4, 'reference' => 'SF-1', 'price' => '413.181818', 'active' => '1', 'visibility' => 'both',
                        'date_upd' => '2026-10-20 11:00:00', 'name' => [['id' => '1', 'value' => 'Stadsfiets']], 'description_short' => [['id' => '1', 'value' => '<p>Fiets</p>']],
                        'associations' => ['categories' => [['id' => '2']]]]]],
                    str_ends_with($path, '/api/cart_rules') => ['cart_rules' => [
                        ['id' => 3, 'code' => 'WELKOM10', 'reduction_percent' => '10.00', 'reduction_amount' => '0.000000', 'reduction_currency' => '1', 'reduction_product' => '0', 'free_shipping' => '0',
                            'minimum_amount' => '50.000000', 'minimum_amount_currency' => '1', 'quantity' => '99', 'quantity_per_user' => '1', 'cart_rule_restriction' => '0', 'active' => '1',
                            'id_customer' => '0', 'group_restriction' => '0', 'product_restriction' => '0', 'date_to' => '2026-12-31 23:59:59', 'date_upd' => '2026-10-21 12:00:00'],
                        ['id' => 4, 'code' => '', 'reduction_percent' => '5.00', 'active' => '1']]],
                },
                // 413.181818 has more decimals than EUR allows: no price is guessed.
                ['external_id' => '4', 'price_minor' => null, 'sku' => 'SF-1', 'name' => 'Stadsfiets'],
                ['external_id' => '3', 'discount_type' => 'percent', 'percent_bps' => 1000, 'minimum_spend_minor' => 5000, 'usage_limit' => 99, 'usage_limit_per_customer' => 1]],
            'shopware' => [EcommercePlatform::Shopware6, ['store_url' => self::URL, 'client_id' => 'SYNTHETIC_shopware_client', 'client_secret' => 'SYNTHETIC_shopware_secret', 'sales_channel_id' => self::CHANNEL],
                fn (string $path) => match (true) {
                    str_ends_with($path, '/api/oauth/token') => ['token_type' => 'Bearer', 'expires_in' => 600, 'access_token' => 'SYNTHETIC_shopware_token'],
                    str_ends_with($path, '/api/search/sales-channel-domain') => ['data' => [['salesChannelId' => self::CHANNEL, 'url' => self::URL]]],
                    str_ends_with($path, '/api/search/currency') => ['total' => 1, 'data' => [['id' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca', 'isoCode' => 'EUR']]],
                    str_ends_with($path, '/api/search/product') => ['total' => 1, 'data' => [['id' => str_repeat('c', 32), 'productNumber' => 'SW10001', 'name' => 'Stadsfiets', 'active' => true,
                        'available' => true, 'stock' => 3, 'availableStock' => 3, 'updatedAt' => '2026-10-20T09:00:00.000+00:00',
                        'price' => [['currencyId' => 'b7d2554b0ce847cd82f3ac9bd1c0dfca', 'gross' => 499.95, 'net' => 413.18, 'linked' => true]],
                        'cover' => ['media' => ['url' => self::URL.'/media/sf.jpg']], 'categories' => [['id' => str_repeat('d', 32), 'name' => 'Stadsfietsen']]]]],
                    str_ends_with($path, '/api/search/promotion') => ['total' => 2, 'data' => [
                        ['id' => str_repeat('e', 32), 'active' => true, 'code' => 'WELKOM10', 'useCodes' => true, 'useIndividualCodes' => false, 'maxRedemptionsGlobal' => null,
                            'maxRedemptionsPerCustomer' => 1, 'orderCount' => 5, 'exclusive' => false, 'preventCombination' => true, 'customerRestriction' => false,
                            'validUntil' => '2026-12-31T23:00:00.000+00:00', 'updatedAt' => '2026-10-21T10:00:00.000+00:00',
                            'discounts' => [['id' => str_repeat('f', 32), 'scope' => 'cart', 'type' => 'percentage', 'value' => 10, 'considerAdvancedRules' => false]]],
                        ['id' => str_repeat('1', 32), 'active' => true, 'code' => null, 'useCodes' => true, 'useIndividualCodes' => true, 'discounts' => []]]],
                },
                ['external_id' => str_repeat('c', 32), 'price_minor' => 49995, 'sku' => 'SW10001', 'stock_quantity' => 3],
                ['external_id' => str_repeat('e', 32), 'discount_type' => 'percent', 'percent_bps' => 1000, 'stackable' => false, 'usage_count' => 5]],
            'wix' => [EcommercePlatform::Wix, ['instance_id' => self::WIX_INSTANCE, 'site_id' => self::WIX_SITE, 'access_token' => 'SYNTHETIC_wix_token', 'public_key' => 'PUBLIC_KEY'],
                fn (string $path) => match (true) {
                    str_ends_with($path, '/apps/v1/instance') => ['instance' => ['instanceId' => self::WIX_INSTANCE], 'site' => ['siteId' => self::WIX_SITE, 'url' => self::URL]],
                    str_ends_with($path, '/site-properties/v4/properties') => ['properties' => ['paymentCurrency' => 'EUR']],
                    str_ends_with($path, '/stores/v3/provision/version') => ['catalogVersion' => 'V3_CATALOG'],
                    str_ends_with($path, '/stores/v3/products/query') => ['products' => [['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Stadsfiets', 'visible' => true,
                        'productType' => 'PHYSICAL', 'plainDescription' => '<p>Fiets</p>', 'currency' => 'EUR', 'actualPriceRange' => ['minValue' => ['amount' => '499.95']],
                        'inventory' => ['availabilityStatus' => 'IN_STOCK'], 'url' => ['url' => self::URL.'/product-page/stadsfiets'],
                        'media' => ['main' => ['image' => ['url' => 'https://static.wixstatic.com/media/sf.jpg']]], 'updatedDate' => '2026-10-20T09:00:00.000Z',
                        'variantsInfo' => ['variants' => [['sku' => 'WX-1']]]]], 'pagingMetadata' => ['count' => 1, 'hasNext' => false, 'cursors' => []]],
                    str_ends_with($path, '/stores/v2/coupons/query') => ['totalResults' => 2, 'coupons' => [
                        ['id' => '44444444-4444-4444-8444-444444444444', 'specification' => ['name' => 'Welkom', 'code' => 'WELKOM5', 'percentOffRate' => 5, 'limitedToOneItem' => false,
                            'minimumSubtotal' => 50, 'usageLimit' => 100, 'limitPerCustomer' => 1, 'active' => true, 'expirationTime' => '1798758000000',
                            'scope' => ['namespace' => 'stores', 'group' => ['name' => 'product', 'entityId' => '33333333-3333-4333-8333-333333333333']]], 'expired' => false, 'numberOfUsages' => 1],
                        ['id' => '55555555-5555-4555-8555-555555555555', 'specification' => ['name' => 'B2G1', 'code' => 'B2G1', 'buyXGetY' => ['x' => 2, 'y' => 1]], 'expired' => false]]],
                },
                ['external_id' => '33333333-3333-4333-8333-333333333333', 'price_minor' => 49995, 'stock_status' => 'instock', 'url' => self::URL.'/product-page/stadsfiets'],
                ['external_id' => '44444444-4444-4444-8444-444444444444', 'discount_type' => 'percent', 'percent_bps' => 500, 'minimum_spend_minor' => 5000,
                    'scope' => ['product_ids' => ['33333333-3333-4333-8333-333333333333']], 'expires_at' => '2026-12-31T23:00:00+00:00']],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $coupon
     */
    #[DataProvider('platforms')]
    public function test_native_catalog_and_coupons_sync_read_only(EcommercePlatform $platform, array $input, \Closure $routes, array $product, array $coupon): void
    {
        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        if ($platform === EcommercePlatform::Wix) {
            $input['public_key'] = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048]))['key'];
        }
        [$c] = app(ConnectionManager::class)->create($shop->memberships()->sole(), $platform, $input);
        Http::fake(fn (Request $r) => Http::response($routes((string) parse_url($r->url(), PHP_URL_PATH)) ?? ['error' => 'unexpected'], 200));
        $sync = app(CatalogSynchronizer::class);
        foreach (['products', 'coupons'] as $kind) {
            $done = $sync->run($sync->start($c, $kind, $owner->id)->id);
            $this->assertSame(['completed', null, 1], [$done->status, $done->error_code, $done->items], $platform->value.' '.$kind);
        }
        $p = ConnectionProduct::sole();
        foreach ($product as $k => $v) {
            $this->assertSame($v, $p->{$k}, $platform->value.' product '.$k);
        }
        $this->assertSame('EUR', $p->currency);
        $row = ConnectionCoupon::sole();
        foreach ($coupon as $k => $v) {
            $this->assertSame($v, $k === 'expires_at' ? $row->expires_at?->toIso8601String() : $row->{$k}, $platform->value.' coupon '.$k);
        }
        $this->assertTrue($row->isActive(), $platform->value);
        $this->assertStringNotContainsString($row->code, (string) DB::table('connection_coupons')->value('code'));
        // Shopware's token grant is the only POST besides Wix/Shopware read queries; nothing writes.
        Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['PUT', 'PATCH', 'DELETE'], true));
    }
}
