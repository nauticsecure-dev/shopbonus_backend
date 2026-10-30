<?php

namespace Tests\Feature\Integrations;

use App\Models\AffiliateConnection;
use App\Models\AffiliateTransaction;
use App\Models\MerchantCommissionConversion;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Cashback\RewardSourceGuard;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

    public function test_connection_capability_report_is_honest_before_verification(): void
    {
        $created = $this->postJson($this->url('/connections'), ['platform' => 'woocommerce'])->assertCreated();
        $id = $created->json('data.id');

        // No authenticated event and no completed sync yet: nothing is claimed AVAILABLE.
        $caps = collect($this->getJson($this->url("/connections/{$id}/capabilities"))->assertOk()->json('data'))->pluck('status', 'capability');
        $this->assertSame(['LIMITED', 'LIMITED', 'LIMITED', 'UNSUPPORTED'], [$caps['webhooks'], $caps['products'], $caps['orders'], $caps['coupons_write']]);
        $this->getJson($this->url("/connections/{$id}/catalog-syncs"))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_woocommerce_product_and_coupon_sync_marks_expired_coupons_inactive(): void
    {
        $created = $this->postJson($this->url('/connections'), [
            'platform' => 'woocommerce',
            'store_url' => 'https://fietsen-utrecht.nl',
            'consumer_key' => 'ck_'.str_repeat('a', 20),
            'consumer_secret' => 'cs_'.str_repeat('b', 20),
        ])->assertCreated();
        $id = $created->json('data.id');

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/data/currencies/current')) {
                return Http::response(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '&euro;']);
            }
            if (str_ends_with($path, '/products')) {
                return Http::response([[
                    'id' => 101, 'name' => 'Blue Mug', 'sku' => 'MUG-1', 'type' => 'simple', 'status' => 'publish', 'catalog_visibility' => 'visible',
                    'price' => '12.50', 'regular_price' => '12.50', 'sale_price' => '', 'permalink' => 'https://fietsen-utrecht.nl/product/mug',
                    'description' => 'Ceramic', 'short_description' => '', 'stock_status' => 'instock', 'stock_quantity' => null,
                    'date_modified_gmt' => '2026-10-28T10:00:00', 'images' => [['src' => 'https://fietsen-utrecht.nl/img/mug.jpg']],
                    'categories' => [['id' => 3, 'name' => 'Drinkware', 'slug' => 'drinkware']],
                ]], 200, ['X-WP-TotalPages' => '1']);
            }
            if (str_ends_with($path, '/coupons')) {
                $coupon = fn (int $id, string $code, string $amount, ?string $expires) => [
                    'id' => $id, 'code' => $code, 'discount_type' => 'percent', 'amount' => $amount, 'status' => 'publish', 'date_expires_gmt' => $expires,
                    'individual_use' => true, 'product_ids' => [], 'excluded_product_ids' => [], 'product_categories' => [], 'excluded_product_categories' => [],
                    'usage_limit' => 100, 'usage_limit_per_user' => null, 'usage_count' => 1, 'free_shipping' => false, 'exclude_sale_items' => false,
                    'minimum_amount' => '0.00', 'maximum_amount' => '0.00', 'email_restrictions' => [], 'date_modified_gmt' => '2026-10-21T10:00:00',
                ];

                return Http::response([$coupon(7, 'SAVE10', '10', null), $coupon(8, 'OLD20', '20', '2020-01-01T00:00:00')], 200, ['X-WP-TotalPages' => '1']);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $this->postJson($this->url("/connections/{$id}/catalog-syncs"), ['kind' => 'products'])->assertStatus(202);
        $this->postJson($this->url("/connections/{$id}/catalog-syncs"), ['kind' => 'coupons'])->assertStatus(202);

        $this->getJson($this->url("/connections/{$id}/products"))->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Blue Mug')
            ->assertJsonPath('data.0.price_minor', 1250)->assertJsonPath('data.0.categories.0.name', 'Drinkware');
        $coupons = collect($this->getJson($this->url("/connections/{$id}/coupons"))->assertOk()->assertJsonCount(2, 'data')->json('data'))->pluck('active', 'code');
        $this->assertSame(['SAVE10' => true, 'OLD20' => false], $coupons->all());
    }

    public function test_reward_source_guard_blocks_cross_path_duplicates(): void
    {
        $guard = app(RewardSourceGuard::class);
        $hash = $guard->orderReferenceHash($this->shop->id, 'ORDER-42');

        $connection = AffiliateConnection::create([
            'network' => 'daisycon',
            'name' => 'Fixture',
            'status' => 'connected',
            'credentials' => [],
            'postback_token_hash' => hash('sha256', 'fixture-catalog'),
        ]);

        AffiliateTransaction::create([
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

        MerchantCommissionConversion::create([
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
