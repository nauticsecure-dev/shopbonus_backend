<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\CapabilityMatrix;
use App\Models\CatalogSnapshotPage;
use App\Models\ConnectionCoupon;
use App\Models\ConnectionProduct;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * D-110: OpenCart products and coupons arrive as signed snapshot pages pushed by the extension worker,
 * then flow through the normal catalog sync. Field semantics follow OpenCart 4.1 source.
 */
class OpenCartCatalogSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private WebshopConnection $connection;

    private string $secret;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id]);
        Sanctum::actingAs($owner);
        $response = $this->postJson("/api/v1/workspaces/{$shop->public_id}/connections", ['platform' => 'opencart'])->assertCreated();
        $this->connection = WebshopConnection::where('public_id', $response->json('data.id'))->sole();
        $this->secret = (string) collect($response->json('data.secrets'))->first();
        $this->path = parse_url($response->json('data.setup.webhook_url'), PHP_URL_PATH).'/catalog';
        $this->app['auth']->forgetGuards();
    }

    private function push(array $page, string $topic = 'catalog.snapshot', ?string $secret = null, ?string $path = null): TestResponse
    {
        $body = json_encode(['schema' => 'shopbonus.catalog.v1', 'currency' => 'EUR', ...$page], JSON_THROW_ON_ERROR);
        $t = time();

        return $this->call('POST', $path ?? $this->path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SHOPBONUS_TOPIC' => $topic,
            'HTTP_X_SHOPBONUS_EVENT_ID' => bin2hex(random_bytes(16)),
            'HTTP_X_SHOPBONUS_SIGNATURE' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret ?? $this->secret),
        ], $body);
    }

    private function product(int $id, string $price = '19.9500'): array
    {
        return ['product_id' => $id, 'model' => 'MODEL-'.$id, 'sku' => '', 'name' => 'Fiets '.$id, 'description' => '<p>Sterk &amp; licht</p>',
            'price' => $price, 'quantity' => 4, 'status' => 1, 'date_modified' => '2026-11-04T10:00:00+01:00'];
    }

    public function test_a_complete_snapshot_feeds_the_normal_catalog_sync(): void
    {
        $snapshot = str_repeat('a', 32);
        $this->push(['kind' => 'products', 'snapshot_id' => $snapshot, 'page' => 1, 'last' => false, 'items' => [$this->product(1)]])
            ->assertStatus(202)->assertJsonPath('data.complete', false);
        $this->assertSame(0, ConnectionProduct::count());
        $this->push(['kind' => 'products', 'snapshot_id' => $snapshot, 'page' => 2, 'last' => true, 'items' => [$this->product(2, '5')]])
            ->assertStatus(202)->assertJsonPath('data.complete', true);

        // The sync job runs synchronously in tests and reads both pages back.
        $rows = ConnectionProduct::where('connection_id', $this->connection->id)->orderBy('external_id')->get();
        $this->assertSame(['1', '2'], $rows->pluck('external_id')->all());
        $this->assertSame(['MODEL-1', 'Fiets 1', 'Sterk & licht', 1995, 4, 'enabled', 'EUR'],
            [$rows[0]->sku, $rows[0]->name, $rows[0]->description, $rows[0]->price_minor, $rows[0]->stock_quantity, $rows[0]->status, $rows[0]->currency]);
        $this->assertSame('2026-11-04T09:00:00+00:00', $rows[0]->provider_updated_at->toIso8601String());
        $this->assertSame(500, $rows[1]->price_minor);

        $coupons = str_repeat('b', 32);
        $this->push(['kind' => 'coupons', 'snapshot_id' => $coupons, 'page' => 1, 'last' => true, 'items' => [
            ['coupon_id' => 6, 'name' => '-10.00 Discount', 'code' => '1111', 'type' => 'F', 'discount' => '10.0000', 'logged' => 0, 'shipping' => 0,
                'total' => '50.0000', 'uses_total' => 10, 'uses_customer' => 1, 'status' => 1, 'expires_at' => '2026-12-31T00:00:00+01:00', 'product_ids' => [1], 'category_ids' => []],
            ['coupon_id' => 4, 'name' => '-10% Discount', 'code' => '2222', 'type' => 'P', 'discount' => '10.0000', 'logged' => 1, 'shipping' => 1,
                'total' => '0.0000', 'uses_total' => 0, 'uses_customer' => 0, 'status' => 0, 'expires_at' => null, 'product_ids' => [], 'category_ids' => [20]],
        ]])->assertStatus(202);
        $fixed = ConnectionCoupon::where('external_id', '6')->sole();
        $this->assertSame(['fixed_cart', 1000, null, 5000, 10, 1, false, false, ['product_ids' => ['1']], 'enabled', '2026-12-30T23:00:00+00:00'],
            [$fixed->discount_type, $fixed->amount_minor, $fixed->percent_bps, $fixed->minimum_spend_minor, $fixed->usage_limit, $fixed->usage_limit_per_customer,
                $fixed->free_shipping, $fixed->customer_restricted, $fixed->scope, $fixed->status, $fixed->expires_at->toIso8601String()]);
        $percent = ConnectionCoupon::where('external_id', '4')->sole();
        $this->assertSame(['percent', null, 1000, null, null, true, true, ['category_ids' => ['20']], 'disabled', null],
            [$percent->discount_type, $percent->amount_minor, $percent->percent_bps, $percent->minimum_spend_minor, $percent->usage_limit,
                $percent->free_shipping, $percent->customer_restricted, $percent->scope, $percent->status, $percent->expires_at]);

        // A newer complete snapshot replaces the old one; products no longer in it are marked removed.
        $this->push(['kind' => 'products', 'snapshot_id' => str_repeat('c', 32), 'page' => 1, 'last' => true, 'items' => [$this->product(2, '6')]])->assertStatus(202);
        $this->assertNotNull(ConnectionProduct::where('external_id', '1')->sole()->removed_at);
        $this->assertSame(600, ConnectionProduct::where('external_id', '2')->sole()->price_minor);
        $this->assertSame(0, CatalogSnapshotPage::where('snapshot_id', $snapshot)->count());

        $caps = app(CapabilityMatrix::class)->forPlatform(EcommercePlatform::OpenCart);
        $this->assertSame(['AVAILABLE', 'AVAILABLE'], [$caps['products']['state'], $caps['coupons_read']['state']]);
    }

    public function test_signature_topic_shape_and_conflicting_redelivery_are_refused(): void
    {
        $page = ['kind' => 'products', 'snapshot_id' => str_repeat('d', 32), 'page' => 1, 'last' => true, 'items' => [$this->product(1)]];
        $this->push($page, secret: str_repeat('x', 40))->assertStatus(401)->assertJsonPath('error.code', 'webhook_rejected');
        $this->push($page, topic: 'order.updated')->assertStatus(401);
        $this->push(['kind' => 'orders'] + $page)->assertStatus(422)->assertJsonPath('error.code', 'catalog_snapshot_invalid');
        $this->push(['items' => [['nested', 'list']]] + $page)->assertStatus(422);
        $this->push(['items' => array_fill(0, 101, $this->product(1))] + $page)->assertStatus(422);
        $this->assertSame(0, CatalogSnapshotPage::count());

        $this->push($page)->assertStatus(202);
        $this->push($page)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->push(['items' => [$this->product(1, '1')]] + $page)->assertStatus(409)->assertJsonPath('error.code', 'catalog_snapshot_conflict');

        // Only OpenCart connections take snapshots.
        $other = str_replace('/opencart/', '/woocommerce/', $this->path);
        $this->push($page, path: $other)->assertNotFound();
    }

    public function test_the_extension_worker_builder_produces_pages_the_backend_accepts(): void
    {
        require_once base_path('merchant-packages/opencart4/system/library/OrderPayload.php');
        require_once base_path('merchant-packages/opencart4/system/library/CatalogPayload.php');
        // OpenCart-shaped rows as mysqli returns them (all strings); a subclass that never connects.
        $db = new class extends \mysqli
        {
            public function __construct() {}

            public function query(string $query, int $resultMode = MYSQLI_STORE_RESULT): \mysqli_result|bool
            {
                $rows = match (true) {
                    str_contains($query, 'FROM `oc_setting`') => [['key' => 'config_currency', 'value' => 'EUR'], ['key' => 'config_language', 'value' => 'nl-nl']],
                    str_contains($query, 'FROM `oc_language`') => [['language_id' => '2']],
                    str_contains($query, 'FROM `oc_coupon_product`') => [['coupon_id' => '6', 'product_id' => '42']],
                    str_contains($query, 'FROM `oc_coupon_category`') => [],
                    str_contains($query, 'FROM `oc_coupon`') => [['coupon_id' => '6', 'name' => 'Tien eraf', 'code' => 'TIEN', 'type' => 'F', 'discount' => '10.0000', 'logged' => '0',
                        'shipping' => '0', 'total' => '50.0000', 'date_end' => '2026-12-31', 'uses_total' => '0', 'uses_customer' => '1', 'status' => '1']],
                    str_contains($query, 'FROM `oc_product`') => [['product_id' => '42', 'model' => 'FB-42', 'sku' => '', 'price' => '24.9900', 'quantity' => '3', 'status' => '1',
                        'date_modified' => '2026-11-04 10:00:00', 'name' => 'Fietsbel', 'description' => '&lt;p&gt;Luid&lt;/p&gt;'], ['product_id' => '43', 'model' => 'X', 'sku' => '',
                            'price' => '1.0000', 'quantity' => '0', 'status' => '0', 'date_modified' => '2026-11-04 10:00:00', 'name' => null, 'description' => null]],
                    default => [],
                };

                return new class($rows) extends \mysqli_result
                {
                    public function __construct(private array $rows) {}

                    public function getIterator(): \Iterator
                    {
                        return new \ArrayIterator($this->rows);
                    }

                    public function fetch_assoc(): array|null|false
                    {
                        return array_shift($this->rows);
                    }
                };
            }

            public function real_escape_string(string $string): string
            {
                return addslashes($string);
            }
        };
        $context = \ShopbonusOpenCartCatalog::context($db, 'oc_');
        $this->assertSame(['currency' => 'EUR', 'language_id' => 2], $context);
        $products = \ShopbonusOpenCartCatalog::products($db, 'oc_', 2, 0, 'Europe/Amsterdam');
        $coupons = \ShopbonusOpenCartCatalog::coupons($db, 'oc_', 0, 'Europe/Amsterdam');
        $this->assertCount(1, $products['items']); // the product without a default-language name is skipped

        $this->push(['kind' => 'products', 'snapshot_id' => str_repeat('f', 32), 'page' => 1, 'last' => ! $products['more'], 'currency' => $context['currency'], 'items' => $products['items']])->assertStatus(202);
        $this->push(['kind' => 'coupons', 'snapshot_id' => str_repeat('9', 32), 'page' => 1, 'last' => ! $coupons['more'], 'currency' => $context['currency'], 'items' => $coupons['items']])->assertStatus(202);
        $product = ConnectionProduct::sole();
        $this->assertSame(['42', 'FB-42', 'Fietsbel', 'Luid', 2499, '2026-11-04T09:00:00+00:00'],
            [$product->external_id, $product->sku, $product->name, $product->description, $product->price_minor, $product->provider_updated_at->toIso8601String()]);
        $coupon = ConnectionCoupon::sole();
        $this->assertSame(['fixed_cart', 1000, 5000, ['product_ids' => ['42']], '2026-12-30T23:00:00+00:00'],
            [$coupon->discount_type, $coupon->amount_minor, $coupon->minimum_spend_minor, $coupon->scope, $coupon->expires_at->toIso8601String()]);
    }

    public function test_an_incomplete_snapshot_is_never_read(): void
    {
        $this->push(['kind' => 'products', 'snapshot_id' => str_repeat('e', 32), 'page' => 2, 'last' => true, 'items' => [$this->product(2)]])
            ->assertStatus(202)->assertJsonPath('data.complete', false);
        $this->assertNull(CatalogSnapshotPage::latestComplete($this->connection->id, $this->connection->credential_generation, 'products'));
        $this->assertSame(0, ConnectionProduct::count());
    }
}
