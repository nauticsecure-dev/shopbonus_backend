<?php

namespace Tests\Feature\Integrations;

use App\Models\CatalogSync;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopifyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Webshop $shop;

    /** @var list<string> */
    private array $scopes = ['read_orders', 'read_products', 'read_discounts'];

    /** @var list<string|null> */
    private array $cursors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id, 'verified_domain' => 'fietsen-utrecht.nl',
            'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }

            public function txtRecords(string $host): array
            {
                return [];
            }
        });
        Sanctum::actingAs($owner);
        Http::fake(function (Request $r) {
            $this->assertSame('https://fietsen.myshopify.com/admin/api/2026-10/graphql.json', $r->url());
            $this->assertStringStartsWith('query ', $r['query']);
            $q = $r['query'];
            $data = match (true) {
                str_contains($q, 'ShopbonusIdentity') => ['shop' => ['id' => 'gid://shopify/Shop/1', 'myshopifyDomain' => 'fietsen.myshopify.com', 'primaryDomain' => ['host' => 'fietsen-utrecht.nl']],
                    'currentAppInstallation' => ['accessScopes' => array_map(fn ($s) => ['handle' => $s], $this->scopes)]],
                str_contains($q, 'ShopbonusCurrency') => ['shop' => ['currencyCode' => 'EUR']],
                str_contains($q, 'ShopbonusCatalogProducts') => $this->products($r['variables']['after'] ?? null),
                str_contains($q, 'ShopbonusCatalogDiscounts') => $this->discounts(),
            };

            return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
        });
    }

    private function base(WebshopConnection $c, string $suffix): string
    {
        return '/api/v1/workspaces/'.$this->shop->public_id.'/connections/'.$c->public_id.$suffix;
    }

    private function connection(): WebshopConnection
    {
        $id = $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections', ['platform' => 'shopify',
            'shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'SYNTHETIC_signing_secret', 'access_token' => 'shpat_SYNTHETIC_access_token'])->assertCreated()->json('data.id');

        return WebshopConnection::where('public_id', $id)->sole();
    }

    /** @return array<string, mixed> */
    private function products(?string $after): array
    {
        $this->cursors[] = $after;
        $node = fn (int $id, string $title, string $price) => ['id' => 'gid://shopify/Product/'.$id, 'legacyResourceId' => (string) $id, 'title' => $title,
            'description' => 'Lichte fiets & stevig frame', 'productType' => 'Fietsen', 'status' => 'ACTIVE', 'onlineStoreUrl' => 'https://fietsen-utrecht.nl/products/'.$id,
            'updatedAt' => '2026-10-20T09:00:00Z', 'totalInventory' => 0, 'tracksInventory' => true,
            'featuredMedia' => ['preview' => ['image' => ['url' => 'https://cdn.shopify.com/s/files/'.$id.'.jpg']]],
            'priceRangeV2' => ['minVariantPrice' => ['amount' => $price, 'currencyCode' => 'EUR']],
            'variants' => ['nodes' => [['sku' => 'SH-'.$id]]], 'collections' => ['nodes' => [['legacyResourceId' => '77', 'title' => 'Stadsfietsen']]]];

        return ['products' => $after === null
            ? ['nodes' => [$node(632910392, 'Stadsfiets', '549.95')], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'eyJsYXN0X2lkIjo2MzI5MTAzOTJ9']]
            : ['nodes' => [$node(632910393, 'Bakfiets', '1899.00')], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]];
    }

    /** @return array<string, mixed> */
    private function discounts(): array
    {
        $common = ['status' => 'ACTIVE', 'endsAt' => null, 'updatedAt' => '2026-10-21T10:00:00Z', 'usageLimit' => 100, 'appliesOncePerCustomer' => true, 'asyncUsageCount' => 3,
            'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => true],
            'context' => ['__typename' => 'DiscountBuyerSelectionAll'], 'minimumRequirement' => null];
        $code = fn (string $c, int $n = 1) => ['codesCount' => ['count' => $n], 'codes' => ['nodes' => array_fill(0, min($n, 2), ['code' => $c])]];

        return ['discountNodes' => ['nodes' => [
            ['id' => 'gid://shopify/DiscountCodeNode/1', 'discount' => ['__typename' => 'DiscountCodeBasic'] + [
                'minimumRequirement' => ['__typename' => 'DiscountMinimumSubtotal', 'greaterThanOrEqualToSubtotal' => ['amount' => '50.0', 'currencyCode' => 'EUR']],
                'customerGets' => ['value' => ['__typename' => 'DiscountPercentage', 'percentage' => 0.15],
                    'items' => ['__typename' => 'DiscountProducts', 'products' => ['nodes' => [['legacyResourceId' => '632910392']], 'pageInfo' => ['hasNextPage' => false]]]]] + $common + $code('SYNTHETIC-WELKOM15')],
            ['id' => 'gid://shopify/DiscountCodeNode/2', 'discount' => ['__typename' => 'DiscountCodeBasic'] + [
                'context' => ['__typename' => 'DiscountCustomerSegments'],
                'customerGets' => ['value' => ['__typename' => 'DiscountAmount', 'amount' => ['amount' => '10.0', 'currencyCode' => 'EUR'], 'appliesOnEachItem' => false],
                    'items' => ['__typename' => 'AllDiscountItems', 'allItems' => true]]] + $common + $code('SYNTHETIC-TIENEURO')],
            ['id' => 'gid://shopify/DiscountCodeNode/3', 'discount' => ['__typename' => 'DiscountCodeFreeShipping', 'status' => 'EXPIRED'] + $common + $code('SYNTHETIC-GRATIS')],
            ['id' => 'gid://shopify/DiscountCodeNode/4', 'discount' => ['__typename' => 'DiscountCodeBxgy', 'title' => 'Buy 2 get 1']],
            ['id' => 'gid://shopify/DiscountCodeNode/5', 'discount' => ['__typename' => 'DiscountCodeBasic'] + $common + $code('BULK', 500) + [
                'customerGets' => ['value' => ['__typename' => 'DiscountPercentage', 'percentage' => 0.1], 'items' => ['__typename' => 'AllDiscountItems', 'allItems' => true]]]],
        ], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]];
    }

    public function test_products_follow_native_cursors_and_map_documented_fields(): void
    {
        $c = $this->connection();
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'products'])->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.pages', 2)->assertJsonPath('data.items', 2);
        $this->assertSame([null, 'eyJsYXN0X2lkIjo2MzI5MTAzOTJ9'], $this->cursors);
        $this->getJson($this->base($c, '/products?search=SH-632910392'))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.external_id', '632910392')->assertJsonPath('data.0.price_minor', 54995)->assertJsonPath('data.0.currency', 'EUR')
            ->assertJsonPath('data.0.status', 'active')->assertJsonPath('data.0.stock_status', 'outofstock')->assertJsonPath('data.0.categories.0.name', 'Stadsfietsen')
            ->assertJsonPath('data.0.image_url', 'https://cdn.shopify.com/s/files/632910392.jpg');
        Http::assertNotSent(fn (Request $r) => str_starts_with((string) $r['query'], 'mutation'));
    }

    public function test_single_code_discounts_are_mapped_and_unsupported_shapes_skipped(): void
    {
        $c = $this->connection();
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'coupons'])->assertStatus(202)->assertJsonPath('data.items', 3);
        $coupons = collect($this->getJson($this->base($c, '/coupons'))->assertOk()->json('data'))->keyBy('external_id');
        $this->assertSame([1, 2, 3], $coupons->keys()->sort()->values()->all());
        $this->assertSame(['percent', '15', 1500, 5000, 1, true, false, true, ['product_ids' => ['632910392']]], [$coupons['1']['discount_type'], $coupons['1']['amount'],
            $coupons['1']['percent_bps'], $coupons['1']['minimum_spend_minor'], $coupons['1']['usage_limit_per_customer'], $coupons['1']['stackable'],
            $coupons['1']['customer_restricted'], $coupons['1']['active'], $coupons['1']['scope']]);
        $this->assertSame(['fixed_cart', 1000, true, null], [$coupons['2']['discount_type'], $coupons['2']['amount_minor'], $coupons['2']['customer_restricted'], $coupons['2']['scope']]);
        $this->assertSame(['free_shipping', true, 'expired', false], [$coupons['3']['discount_type'], $coupons['3']['free_shipping'], $coupons['3']['status'], $coupons['3']['active']]);
        $this->assertStringNotContainsString('SYNTHETIC-WELKOM15', json_encode(DB::table('connection_coupons')->pluck('code')));
    }

    public function test_missing_discount_scope_fails_the_pass_without_storing(): void
    {
        $this->scopes = ['read_orders', 'read_products'];
        $c = $this->connection();
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'coupons'])->assertStatus(202)
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'provider_scope_missing');
        $this->assertDatabaseCount('connection_coupons', 0);
        $this->assertSame('LIMITED', collect($this->getJson($this->base($c, '/capabilities'))->json('data'))->firstWhere('capability', 'coupons_read')['status']);
        $this->assertSame(1, CatalogSync::count());
    }
}
