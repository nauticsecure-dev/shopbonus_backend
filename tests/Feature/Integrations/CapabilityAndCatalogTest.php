<?php

namespace Tests\Feature\Integrations;

use App\Models\CatalogCoupon;
use App\Models\CatalogProduct;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Cashback\RewardSourceGuard;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CapabilityAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create([
            'created_by' => $this->owner->id,
            'ownership_verified_at' => now(),
            'ownership_method' => 'dns_txt',
            'verified_domain' => 'fietsen-utrecht.nl',
        ]);
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
        Sanctum::actingAs($this->owner);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}{$suffix}";
    }

    public function test_platform_catalogue_includes_capability_matrix(): void
    {
        $platforms = collect($this->getJson('/api/v1/integrations/platforms')->assertOk()->json('data'))->keyBy('platform');

        $this->assertArrayHasKey('capabilities', $platforms['woocommerce']);
        $this->assertSame('AVAILABLE', $platforms['woocommerce']['capabilities']['orders']['state']);
        $this->assertSame('AVAILABLE', $platforms['woocommerce']['capabilities']['products']['state']);
        $this->assertSame('UNSUPPORTED', $platforms['shopify']['capabilities']['coupons_read']['state']);
        $this->assertTrue($platforms['lightspeed_c']['available']);
        $this->assertSame('AVAILABLE', $platforms['lightspeed_c']['capabilities']['orders']['state']);
        $this->assertSame('LIMITED', $platforms['lightspeed_c']['capabilities']['banner_embed']['state']);
        $this->assertSame('marketplace', $platforms['lightspeed_c']['category']);
        $this->assertSame('2026-10-30', $platforms['lightspeed_c']['profile']['reviewed_at']);
        $this->assertFalse($platforms['opencart']['profile']['native_read_api_implemented']);
        $this->assertSame('LIMITED', $platforms['opencart']['capabilities']['orders']['state']);
        $this->assertSame('webshop', $platforms['woocommerce']['category']);
    }

    public function test_connection_capabilities_and_health_endpoints(): void
    {
        $created = $this->postJson($this->url('/connections'), ['platform' => 'woocommerce'])->assertCreated();
        $id = $created->json('data.id');

        $caps = $this->getJson($this->url("/connections/{$id}/capabilities"))->assertOk()->json('data.capabilities');
        $this->assertSame('AVAILABLE', $caps['webhooks']['state']);
        $this->assertSame('LIMITED', $caps['products']['state']);

        $health = $this->getJson($this->url("/connections/{$id}/health"))->assertOk()->json('data');
        $this->assertSame(0, $health['products_synced']);
        $this->assertSame(0, $health['eligible_coupons']);
    }

    public function test_woocommerce_product_and_coupon_sync_upserts_and_marks_expired_ineligible(): void
    {
        $created = $this->postJson($this->url('/connections'), [
            'platform' => 'woocommerce',
            'store_url' => 'https://fietsen-utrecht.nl',
            'consumer_key' => 'ck_'.str_repeat('a', 20),
            'consumer_secret' => 'cs_'.str_repeat('b', 20),
        ])->assertCreated();
        $id = $created->json('data.id');
        $connection = WebshopConnection::where('public_id', $id)->sole();

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, '/products')) {
                return Http::response([
                    [
                        'id' => 101,
                        'name' => 'Blue Mug',
                        'sku' => 'MUG-1',
                        'price' => '12.50',
                        'currency' => 'EUR',
                        'permalink' => 'https://fietsen-utrecht.nl/product/mug',
                        'short_description' => 'Ceramic',
                        'stock_status' => 'instock',
                        'date_modified_gmt' => '2026-10-28T10:00:00',
                        'images' => [['src' => 'https://cdn.example/mug.jpg']],
                        'categories' => [['name' => 'Drinkware']],
                    ],
                ], 200, ['X-WP-TotalPages' => '1']);
            }
            if (str_contains($url, '/coupons')) {
                return Http::response([
                    [
                        'id' => 7,
                        'code' => 'SAVE10',
                        'discount_type' => 'percent',
                        'amount' => '10',
                        'status' => 'publish',
                        'date_expires_gmt' => null,
                        'individual_use' => true,
                        'excluded_product_categories' => [],
                        'usage_limit' => 100,
                        'usage_count' => 1,
                    ],
                    [
                        'id' => 8,
                        'code' => 'OLD20',
                        'discount_type' => 'percent',
                        'amount' => '20',
                        'status' => 'publish',
                        'date_expires_gmt' => '2020-01-01T00:00:00',
                        'individual_use' => false,
                        'excluded_product_categories' => [],
                    ],
                ], 200, ['X-WP-TotalPages' => '1']);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $this->postJson($this->url("/connections/{$id}/sync/products"))->assertStatus(202)
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.exhausted', true);

        $this->postJson($this->url("/connections/{$id}/sync/coupons"))->assertStatus(202)
            ->assertJsonPath('data.imported', 2);

        $this->assertSame(1, CatalogProduct::where('connection_id', $connection->id)->count());
        $product = CatalogProduct::where('external_product_id', '101')->sole();
        $this->assertSame('Blue Mug', $product->name);
        $this->assertSame(1250, $product->price_minor);
        $this->assertSame('Drinkware', $product->category);

        $eligible = CatalogCoupon::where('connection_id', $connection->id)->where('eligible_public', true)->get();
        $this->assertCount(1, $eligible);
        $this->assertSame('SAVE10', $eligible->first()->code);
        $this->assertFalse(CatalogCoupon::where('code', 'OLD20')->sole()->eligible_public);

        $this->getJson($this->url("/connections/{$id}/products"))->assertOk()
            ->assertJsonPath('data.0.name', 'Blue Mug');
        $this->getJson($this->url("/connections/{$id}/coupons"))->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_reward_source_guard_blocks_cross_path_duplicates(): void
    {
        $guard = app(RewardSourceGuard::class);
        $hash = $guard->orderReferenceHash($this->shop->id, 'ORDER-42');

        $connection = \App\Models\AffiliateConnection::create([
            'network' => 'daisycon',
            'name' => 'Fixture',
            'status' => 'connected',
            'credentials' => [],
            'postback_token_hash' => hash('sha256', 'fixture-catalog'),
        ]);

        \App\Models\AffiliateTransaction::create([
            'network' => 'daisycon',
            'connection_id' => $connection->id,
            'external_id' => 'aff-catalog-1',
            'webshop_id' => $this->shop->id,
            'order_reference_hash' => $hash,
            'order_amount_minor' => 1000,
            'commission_minor' => 100,
            'currency' => 'EUR',
            'network_status' => 'approved',
            'status' => 'approved',
            'reward_status' => 'credited',
            'transaction_at' => now(),
        ]);

        $this->assertSame('affiliate_reward_already_credited', $guard->assertDirectAllowed($this->shop->id, 'ORDER-42'));
        $this->assertNull($guard->assertDirectAllowed($this->shop->id, 'OTHER'));

        \App\Models\MerchantCommissionConversion::create([
            'webshop_id' => $this->shop->id,
            'user_id' => $this->owner->id,
            'agreement_id' => null,
            'external_id' => 'DIRECT-9',
            'idempotency_key' => 'idem-direct-9',
            'order_amount_minor' => 5000,
            'commission_minor' => 500,
            'consumer_credit_minor' => 350,
            'shopbonus_revenue_minor' => 150,
            'currency' => 'EUR',
            'status' => 'credited',
            'agreement_snapshot' => [],
            'converted_at' => now(),
        ]);

        $this->assertSame('direct_merchant_reward_already_credited', $guard->assertAffiliateAllowed($this->shop->id, 'DIRECT-9'));
    }
}
