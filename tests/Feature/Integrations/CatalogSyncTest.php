<?php

namespace Tests\Feature\Integrations;

use App\Enums\MembershipRole;
use App\Models\CatalogSync;
use App\Models\ConnectionCoupon;
use App\Models\ConnectionProduct;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Catalog\CatalogSynchronizer;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private Webshop $shop;

    private User $owner;

    /** @var array<string, list<array<string, mixed>>> */
    private array $remote = [];

    private int $totalPages = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id,
            'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt', 'verified_domain' => 'fietsen-utrecht.nl']);
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
        $this->remote = ['products' => [$this->product(11, 'Stadsfiets Comfort', '549.95')], 'coupons' => [$this->coupon(21, 'SYNTHETIC-WELKOM10')]];
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->assertSame('GET', $request->method());
            if (str_ends_with($path, '/data/currencies/current')) {
                return Http::response(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '&euro;']);
            }
            $kind = str_ends_with($path, '/products') ? 'products' : 'coupons';

            return Http::response($this->remote[$kind], 200, ['X-WP-TotalPages' => (string) $this->totalPages]);
        });
    }

    private function connect(): WebshopConnection
    {
        $id = $this->postJson($this->base(), ['platform' => 'woocommerce', 'store_url' => 'https://fietsen-utrecht.nl',
            'consumer_key' => 'ck_SYNTHETIC_read_key', 'consumer_secret' => 'cs_SYNTHETIC_read_secret'])->assertCreated()->json('data.id');

        return WebshopConnection::where('public_id', $id)->sole();
    }

    private function base(?WebshopConnection $c = null, string $suffix = ''): string
    {
        return '/api/v1/workspaces/'.$this->shop->public_id.'/connections'.($c ? '/'.$c->public_id.$suffix : '');
    }

    /** @return array<string, mixed> */
    private function product(int $id, string $name, string $price): array
    {
        return ['id' => $id, 'name' => $name, 'sku' => 'SKU-'.$id, 'permalink' => 'https://fietsen-utrecht.nl/product/'.$id, 'type' => 'simple', 'status' => 'publish',
            'catalog_visibility' => 'visible', 'description' => '<p>Een <strong>comfortabele</strong> fiets &amp; meer</p>', 'short_description' => '',
            'price' => $price, 'regular_price' => $price, 'sale_price' => '', 'stock_status' => 'instock', 'stock_quantity' => 4,
            'categories' => [['id' => 5, 'name' => 'Stadsfietsen', 'slug' => 'stadsfietsen']], 'images' => [['src' => 'https://fietsen-utrecht.nl/img/'.$id.'.jpg']],
            'date_modified_gmt' => '2026-10-20T09:00:00'];
    }

    /** @return array<string, mixed> */
    private function coupon(int $id, string $code): array
    {
        return ['id' => $id, 'code' => $code, 'amount' => '10.00', 'discount_type' => 'percent', 'status' => 'publish', 'date_expires_gmt' => null,
            'usage_count' => 3, 'individual_use' => true, 'product_ids' => [11], 'excluded_product_ids' => [], 'usage_limit' => 100, 'usage_limit_per_user' => 1,
            'free_shipping' => false, 'product_categories' => [], 'excluded_product_categories' => [], 'exclude_sale_items' => true, 'minimum_amount' => '50.00',
            'maximum_amount' => '0.00', 'email_restrictions' => ['anna@example.nl'], 'date_modified_gmt' => '2026-10-21T10:00:00'];
    }

    public function test_woocommerce_products_and_coupons_are_synced_read_only_and_coupon_codes_encrypted(): void
    {
        $c = $this->connect();
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'products'])->assertStatus(202)->assertJsonPath('data.kind', 'products');
        $this->assertSame('completed', CatalogSync::where('kind', 'products')->sole()->status);
        $this->getJson($this->base($c, '/products'))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.external_id', '11')->assertJsonPath('data.0.price_minor', 54995)->assertJsonPath('data.0.currency', 'EUR')
            ->assertJsonPath('data.0.description', 'Een comfortabele fiets & meer')->assertJsonPath('data.0.categories.0.name', 'Stadsfietsen');

        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'coupons'])->assertStatus(202);
        $this->getJson($this->base($c, '/coupons'))->assertOk()->assertJsonPath('data.0.code', 'SYNTHETIC-WELKOM10')->assertJsonPath('data.0.percent_bps', 1000)
            ->assertJsonPath('data.0.minimum_spend_minor', 5000)->assertJsonPath('data.0.maximum_spend_minor', null)->assertJsonPath('data.0.stackable', false)
            ->assertJsonPath('data.0.customer_restricted', true)->assertJsonPath('data.0.active', true)->assertDontSee('anna@example.nl');
        $this->assertStringNotContainsString('SYNTHETIC-WELKOM10', (string) DB::table('connection_coupons')->value('code'));
        $this->assertStringNotContainsString('anna@example.nl', json_encode(DB::table('connection_coupons')->get()));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        $this->assertDatabaseHas('audit_events', ['action' => 'integration.catalog_sync_finished']);

        $caps = collect($this->getJson($this->base($c, '/capabilities'))->assertOk()->json('data'))->pluck('status', 'capability');
        $this->assertSame(['AVAILABLE', 'AVAILABLE', 'AVAILABLE', 'UNSUPPORTED', 'LIMITED', 'LIMITED'],
            [$caps['products'], $caps['categories'], $caps['coupons_read'], $caps['coupons_write'], $caps['banner_embed'], $caps['orders']]);
        $this->getJson($this->base($c, '/catalog-syncs'))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_items_missing_from_a_complete_pass_are_marked_removed_but_not_after_a_partial_pass(): void
    {
        $c = $this->connect();
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'products'])->assertStatus(202);
        $this->remote['products'] = [$this->product(12, 'Bakfiets', '1899.00')];
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'products'])->assertStatus(202);
        $this->assertNotNull(ConnectionProduct::where('external_id', '11')->sole()->removed_at);
        $this->getJson($this->base($c, '/products'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Bakfiets');
        $this->getJson($this->base($c, '/products?include_removed=1'))->assertJsonPath('meta.total', 2);

        // A page cap reached before the provider's last page never removes anything.
        config(['integrations.catalog.max_pages' => 1]);
        $this->totalPages = 3;
        $this->remote['products'] = array_map(fn ($i) => $this->product(100 + $i, 'Fiets '.$i, '100.00'), range(1, 100));
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'products'])->assertStatus(202);
        $last = CatalogSync::latest('id')->first();
        $this->assertSame(['partial', 'catalog_page_limit', 0], [$last->status, $last->error_code, $last->removed]);
        $this->assertNull(ConnectionProduct::where('external_id', '12')->sole()->removed_at);
    }

    public function test_rotated_credentials_discard_a_pass_started_with_the_old_generation(): void
    {
        $c = $this->connect();
        $sync = app(CatalogSynchronizer::class)->start($c, 'coupons', $this->owner->id);
        $c->forceFill(['credential_generation' => $c->credential_generation + 1])->save();
        $this->assertSame('credentials_changed', app(CatalogSynchronizer::class)->run($sync->id)->error_code);
        $this->assertSame(0, ConnectionCoupon::count());
    }

    public function test_conflicts_permissions_and_workspace_isolation(): void
    {
        $c = $this->connect();
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'orders'])->assertUnprocessable();
        CatalogSync::create(['connection_id' => $c->id, 'kind' => 'products', 'status' => 'running', 'credential_generation' => $c->credential_generation, 'started_at' => now()]);
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'products'])->assertConflict()->assertJsonPath('error.code', 'catalog_sync_running');

        $c->forceFill(['status' => 'disconnected'])->save();
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'coupons'])->assertConflict()->assertJsonPath('error.code', 'connection_disconnected');

        $viewer = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $viewer->id, 'role' => MembershipRole::Employee, 'permissions' => ['integrations.view'], 'status' => 'active']);
        Sanctum::actingAs($viewer);
        $this->getJson($this->base($c, '/products'))->assertOk();
        $this->getJson($this->base($c, '/capabilities'))->assertOk();
        $this->getJson($this->base($c, '/coupons'))->assertForbidden();
        $this->postJson($this->base($c, '/catalog-syncs'), ['kind' => 'products'])->assertForbidden();

        $stranger = User::factory()->create();
        $other = Webshop::factory()->withMember($stranger)->create(['created_by' => $stranger->id]);
        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/workspaces/'.$other->public_id.'/connections/'.$c->public_id.'/products')->assertNotFound();
    }
}
