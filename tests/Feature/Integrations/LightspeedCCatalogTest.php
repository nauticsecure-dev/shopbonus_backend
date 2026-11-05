<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\CapabilityMatrix;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\LightspeedCConnector;
use App\Models\CatalogSync;
use App\Models\ConnectionProduct;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Catalog\CatalogSynchronizer;
use App\Services\Catalog\ConnectionCapabilities;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightspeedCCatalogTest extends TestCase
{
    use RefreshDatabase;

    private WebshopConnection $connection;

    private mixed $products;

    private mixed $variants;

    private string $domain = 'catalog.example.com';

    private string $currency = 'EUR';

    private int $productStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
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
        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id,
            'verified_domain' => $this->domain, 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        [$this->connection] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::LightspeedC,
            ['shop_id' => '100001', 'cluster' => 'eu1', 'language' => 'en', 'api_key' => 'SYNTHETICkey123456', 'api_secret' => 'SYNTHETICsecret123456']);
        $this->products = [$this->product(77)];
        $this->variants = [$this->variant(77)];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->assertStringStartsWith('https://api.webshopapp.com/en/', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Basic '.base64_encode('SYNTHETICkey123456:SYNTHETICsecret123456')));
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/en/shop.json' => Http::response(['shop' => ['id' => 100001, 'mainDomain' => $this->domain, 'status' => 'live', 'currency' => ['shortcode' => $this->currency]]]),
                '/en/products.json' => Http::response(['products' => $this->products], $this->productStatus),
                '/en/variants.json' => Http::response(['variants' => $this->variants]),
                default => throw new \RuntimeException('Unexpected provider path.'),
            };
        });
    }

    private function product(int $id): array
    {
        return ['id' => $id, 'title' => 'Test product', 'content' => '<p>Safe &amp; sound</p>', 'isVisible' => true,
            'url' => 'test-product', 'image' => ['src' => 'https://cdn.shoplightspeed.com/test.jpg'], 'updatedAt' => '2026-11-03T10:00:00+00:00',
            'variants' => ['resource' => ['link' => 'https://attacker.example.test/steal']]];
    }

    private function variant(int $product): array
    {
        return ['id' => 100, 'sku' => 'SKU-1', 'priceIncl' => '19.95', 'oldPriceIncl' => '24.95', 'stockTracking' => 'enabled', 'stockLevel' => 4,
            'product' => ['resource' => ['id' => $product]]];
    }

    private function sync(): CatalogSync
    {
        $sync = app(CatalogSynchronizer::class);

        return $sync->run($sync->start($this->connection, 'products', null)->id);
    }

    public function test_native_product_pass_persists_exact_single_variant_snapshot_and_does_not_verify_live_provider(): void
    {
        $done = $this->sync();
        $this->assertSame(['completed', null, 1], [$done->status, $done->error_code, $done->items]);
        $product = ConnectionProduct::sole();
        $this->assertSame($this->connection->id, $product->connection_id);
        $this->assertSame(['77', 'Safe & sound', 'SKU-1', 1995, 2495, 1995, 4, 'EUR', 'visible'],
            [$product->external_id, $product->description, $product->sku, $product->price_minor, $product->regular_price_minor,
                $product->sale_price_minor, $product->stock_quantity, $product->currency, $product->visibility]);
        $this->assertNull($product->url);
        $this->assertNull($product->categories);
        $this->assertNull(config('integrations.platforms.lightspeed_c.profile.last_live_verified_at'));
        $caps = app(CapabilityMatrix::class)->forPlatform(EcommercePlatform::LightspeedC);
        $this->assertSame('AVAILABLE', $caps['products']['state']);
        $this->assertSame('UNSUPPORTED', $caps['coupons_read']['state']);
        $this->assertSame('UNSUPPORTED', $caps['categories']['state']);
        $connectionCaps = collect(app(ConnectionCapabilities::class)->report($this->connection))->keyBy('capability');
        $this->assertSame('AVAILABLE', $connectionCaps['products']['status']);
        $this->assertSame('UNSUPPORTED', $connectionCaps['categories']['status']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/variants.json?') && $r['product'] === '77' && $r['limit'] === 2);
    }

    public function test_multi_variant_product_has_no_invented_aggregate_money_sku_or_stock(): void
    {
        $this->variants[] = array_replace($this->variant(77), ['id' => 101, 'priceIncl' => '99.95']);
        $this->assertSame('completed', $this->sync()->status);
        $product = ConnectionProduct::sole();
        $this->assertNull($product->price_minor);
        $this->assertNull($product->stock_quantity);
        $this->assertNull($product->sku);
    }

    public function test_full_page_continues_and_short_empty_page_ends(): void
    {
        $this->products = array_map(fn ($id) => $this->product($id), range(1, 25));
        $this->variants = [];
        $reader = new LightspeedCConnector;
        $page = $reader->catalogPage($this->connection, 'products', null, 'EUR');
        $this->assertSame('2', $page['next']);
        $this->assertCount(25, $page['items']);
        $this->products = [];
        $this->assertSame(['items' => [], 'next' => null], $reader->catalogPage($this->connection, 'products', '2', 'EUR'));
    }

    public function test_malformed_page_fails_without_removing_previous_snapshot(): void
    {
        $this->assertSame('completed', $this->sync()->status);
        $this->products = ['unexpected' => $this->product(88)];
        $done = $this->sync();
        $this->assertSame(['failed', 'provider_response_invalid'], [$done->status, $done->error_code]);
        $this->assertNull(ConnectionProduct::sole()->removed_at);
    }

    public function test_completed_empty_pass_marks_previous_products_removed(): void
    {
        $this->assertSame('completed', $this->sync()->status);
        $this->products = [];
        $done = $this->sync();
        $this->assertSame(['completed', 0, 1], [$done->status, $done->items, $done->removed]);
        $this->assertNotNull(ConnectionProduct::sole()->removed_at);
    }

    public function test_oversized_variant_page_and_malformed_product_identity_fail_closed(): void
    {
        $this->variants = array_fill(0, 3, $this->variant(77));
        $this->assertSame('provider_response_invalid', $this->sync()->error_code);
        $this->variants = [];
        $this->products = [array_replace($this->product(77), ['id' => ['invalid']])];
        $this->assertSame('provider_product_invalid', $this->sync()->error_code);
        $this->assertSame(0, ConnectionProduct::count());
    }

    public function test_foreign_variant_and_duplicate_product_fail_without_persisting_page(): void
    {
        $this->variants = [$this->variant(999)];
        $this->assertSame('provider_product_invalid', $this->sync()->error_code);
        $this->variants = [$this->variant(77)];
        $this->products[] = $this->product(77);
        $this->assertSame('provider_product_invalid', $this->sync()->error_code);
        $this->assertSame(0, ConnectionProduct::count());
    }

    public function test_wrong_store_identity_blocks_catalog_reads(): void
    {
        $this->domain = 'foreign.example.com';
        $this->assertSame('store_identity_unverified', $this->sync()->error_code);
        Http::assertSentCount(1);
    }

    public function test_provider_auth_failure_does_not_remove_snapshots(): void
    {
        $this->assertSame('completed', $this->sync()->status);
        $this->productStatus = 401;
        $this->assertSame('provider_authentication_failed', $this->sync()->error_code);
        $this->assertNull(ConnectionProduct::sole()->removed_at);
    }

    public function test_currency_drift_is_refused_before_reading_products(): void
    {
        $this->currency = 'USD';
        try {
            (new LightspeedCConnector)->catalogPage($this->connection, 'products', null, 'EUR');
            $this->fail('Expected currency mismatch.');
        } catch (ApiException $e) {
            $this->assertSame('provider_currency_changed', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_coupon_and_invalid_cursor_do_not_send_provider_requests(): void
    {
        $reader = new LightspeedCConnector;
        try {
            $reader->catalogPage($this->connection, 'coupons', null, 'EUR');
            $this->fail('Expected unsupported coupon boundary.');
        } catch (ApiException $e) {
            $this->assertSame('capability_unsupported', $e->errorCode);
        }
        try {
            $reader->catalogPage($this->connection, 'products', 'https://attacker.test', 'EUR');
            $this->fail('Expected invalid cursor.');
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_paused_connection_blocks_native_reads(): void
    {
        $this->connection->forceFill(['paused_at' => now()])->save();
        try {
            (new LightspeedCConnector)->catalogPage($this->connection, 'products', null, 'EUR');
            $this->fail('Expected pause gate.');
        } catch (ApiException $e) {
            $this->assertSame('connection_paused', $e->errorCode);
        }
        Http::assertNothingSent();
    }
}
