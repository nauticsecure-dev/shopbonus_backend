<?php

namespace Tests\Feature\Banners;

use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\BigCommerceConnector;
use App\Models\BannerInstallation;
use App\Models\User;
use App\Models\WebshopConnection;
use App\Services\Banners\BannerExperiments;
use App\Services\Banners\BannerService;
use App\Services\Banners\BigCommerceCartAssignments;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\TestCase;

class BigCommerceCartAssignmentTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private const CART = 'd30016e2-23c0-4a90-884f-2e92ac135476';

    private BannerInstallation $installation;

    private WebshopConnection $connection;

    private string $visitor;

    private bool $foreignCart = false;

    private array $metafields = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.assignment_metadata.bigcommerce_cart_bridge' => true]);
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner, ['verified_domain' => 'shop.example.com', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        $service = app(BannerService::class);
        $campaign = $service->create($shop, 'Cart assignment', null, BannerFixture::config(), $owner->id);
        $service->publish($campaign, $owner->id);
        app(BannerExperiments::class)->start($campaign, null, 1, 5000, $owner->id);
        $this->installation = $service->installation($shop);
        $this->connection = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::BigCommerce, ['store_hash' => 'abc123', 'access_token' => 'SYNTHETIC_bigcommerce_token', 'client_secret' => 'SYNTHETIC_bigcommerce_secret'])[0];
        $this->visitor = (string) Str::uuid();
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
        Http::fake(function ($request) {
            $this->assertTrue($request->hasHeader('X-Auth-Token', 'SYNTHETIC_bigcommerce_token'));
            if (str_contains($request->url(), '/v2/store')) {
                return Http::response(['id' => 'abc123', 'secure_url' => 'https://shop.example.com']);
            }
            if (str_contains($request->url(), '/v3/carts/')) {
                return Http::response(['data' => ['id' => $this->foreignCart ? (string) Str::uuid() : self::CART]]);
            }
            if (str_contains($request->url(), '/v2/orders/250')) {
                return Http::response(['id' => 250, 'cart_id' => self::CART, 'date_created' => now()->toIso8601String(), 'default_currency_code' => 'EUR', 'currency_code' => 'EUR', 'total_inc_tax' => '10.00']);
            }
            if (str_contains($request->url(), '/transactions') || str_contains($request->url(), '/refunds')) {
                return Http::response(['data' => [], 'meta' => ['pagination' => ['total_pages' => 1]]]);
            }
            if (str_contains($request->url(), '/metafields')) {
                return Http::response(['data' => $this->metafields, 'meta' => ['pagination' => ['total_pages' => 1]]]);
            }
            throw new \RuntimeException('Unexpected provider call: '.$request->url());
        });
    }

    private function payload(): array
    {
        return ['key' => $this->installation->public_key, 'visitor_key' => $this->visitor, 'consent' => true,
            'consent_id' => BannerFixture::consentId(), 'cart_context' => ['platform' => 'bigcommerce', 'cart_id' => self::CART]];
    }

    public function test_native_cart_to_authenticated_order_bridge_is_idempotent_and_preserves_unknown_payment(): void
    {
        $payload = $this->payload();
        $first = $this->postJson('/api/v1/banners/assignments', $payload, ['Origin' => 'https://shop.example.com'])->assertOk()->json('data.0');
        $this->postJson('/api/v1/banners/assignments', $payload, ['Origin' => 'https://shop.example.com'])->assertOk()->assertJsonPath('data.0.assignment_token', $first['assignment_token']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('banner_cart_assignments', 1);
        $stored = json_encode(DB::table('banner_cart_assignments')->first());
        $this->assertStringNotContainsString(self::CART, $stored);
        $this->assertStringNotContainsString($first['assignment_token'], $stored);
        $this->assertStringNotContainsString($this->visitor, $stored);
        $this->travel(1)->seconds();
        $connector = app(BigCommerceConnector::class);
        $order = $connector->normalizeOrder($connector->fetchOrder($this->connection, '250'));
        $this->assertSame($first['assignment_token'], $order->bannerAssignmentToken);
        $this->assertNull($order->paidAt);
        $this->assertNull($order->refundedAt);
    }

    public function test_feature_flag_origin_consent_and_cart_schema_fail_closed(): void
    {
        $payload = $this->payload();
        config(['integrations.assignment_metadata.bigcommerce_cart_bridge' => false]);
        $this->postJson('/api/v1/banners/assignments', $payload, ['Origin' => 'https://shop.example.com'])->assertConflict();
        config(['integrations.assignment_metadata.bigcommerce_cart_bridge' => true]);
        $this->postJson('/api/v1/banners/assignments', $payload, ['Origin' => 'https://foreign.example'])->assertForbidden();
        $this->postJson('/api/v1/banners/assignments', array_replace($payload, ['consent_id' => (string) Str::uuid()]), ['Origin' => 'https://shop.example.com'])->assertForbidden();
        foreach ([[], ['platform' => 'ccv', 'cart_id' => self::CART], ['platform' => 'bigcommerce', 'cart_id' => '250']] as $context) {
            $this->postJson('/api/v1/banners/assignments', array_replace($payload, ['cart_context' => $context]), ['Origin' => 'https://shop.example.com'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('banner_cart_assignments', 0);
        Http::assertNothingSent();
    }

    public function test_foreign_native_cart_is_refused_and_another_visitor_cannot_replace_a_binding(): void
    {
        $payload = $this->payload();
        $this->foreignCart = true;
        $this->postJson('/api/v1/banners/assignments', $payload, ['Origin' => 'https://shop.example.com'])->assertStatus(502)->assertJsonPath('error.code', 'provider_cart_invalid');
        $this->assertDatabaseCount('banner_cart_assignments', 0);
        $this->foreignCart = false;
        $this->postJson('/api/v1/banners/assignments', $payload, ['Origin' => 'https://shop.example.com'])->assertOk();
        $this->postJson('/api/v1/banners/assignments', array_replace($payload, ['visitor_key' => (string) Str::uuid()]), ['Origin' => 'https://shop.example.com'])->assertConflict()->assertJsonPath('error.code', 'cart_assignment_conflict');
        $this->assertDatabaseCount('banner_cart_assignments', 1);
    }

    public function test_revocation_expiry_rotation_and_wrong_cart_or_order_time_prevent_resolution(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/v1/banners/assignments', $payload, ['Origin' => 'https://shop.example.com'])->assertOk();
        $service = app(BigCommerceCartAssignments::class);
        $order = ['cart_id' => self::CART, 'date_created' => now()->addSecond()->toIso8601String()];
        $this->assertNotNull($service->token($this->connection, $order));
        foreach ([null, '', '   ', 'not-a-date'] as $bad) {
            $this->assertNull($service->token($this->connection, array_replace($order, ['date_created' => $bad])));
        }
        $this->assertNull($service->token($this->connection, array_replace($order, ['cart_id' => (string) Str::uuid()])));
        $this->assertNull($service->token($this->connection, array_replace($order, ['date_created' => now()->subDay()->toIso8601String()])));
        $this->connection->credential_generation++;
        $this->assertNull($service->token($this->connection, $order));
        $this->connection->credential_generation--;
        $other = clone $this->connection;
        $other->external_shop_ref = 'other123';
        $this->assertNull($service->token($other, $order));
        $other = clone $this->connection;
        $other->paused_at = now();
        $this->assertNull($service->token($other, $order));
        $other = clone $this->connection;
        $other->status = ConnectionStatus::Disconnected;
        $this->assertNull($service->token($other, $order));
        $this->postJson('/api/v1/banners/assignments', array_replace($payload, ['consent' => false]), ['Origin' => 'https://shop.example.com'])->assertOk();
        $this->assertNull($service->token($this->connection, $order));
        DB::table('banner_assignments')->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $this->assertNull($service->token($this->connection, $order));
    }

    public function test_conflicting_provider_metafields_never_override_the_cart_binding(): void
    {
        $token = $this->postJson('/api/v1/banners/assignments', $this->payload(), ['Origin' => 'https://shop.example.com'])->assertOk()->json('data.0.assignment_token');
        config(['integrations.assignment_metadata.bigcommerce' => true]);
        $row = ['namespace' => 'shopbonus', 'key' => 'assignment_token', 'resource_type' => 'order', 'resource_id' => 250, 'value' => $token];
        $this->metafields = [$row];
        $this->travel(1)->seconds();
        $connector = app(BigCommerceConnector::class);
        $this->assertSame($token, $connector->normalizeOrder($connector->fetchOrder($this->connection, '250'))->bannerAssignmentToken);
        $this->metafields[0]['value'] = 'bx_'.str_repeat('f', 64);
        try {
            $connector->fetchOrder($this->connection, '250');
            $this->fail('Conflicting native evidence accepted.');
        } catch (ApiException $exception) {
            $this->assertSame('provider_assignment_metadata_invalid', $exception->errorCode);
        }
    }
}
