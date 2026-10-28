<?php

namespace Tests\Feature\Integrations;

use App\Enums\MembershipRole;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\WooCommerceConnector;
use App\Integrations\Http\WooCommerceApi;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

class WooCommerceApiTest extends TestCase
{
    use RefreshDatabase, SignsWebhooks;

    private Webshop $shop;

    private User $owner;

    /** @var list<string> */
    private array $addresses = ['93.184.216.34'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id,
            'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt', 'verified_domain' => 'fietsen-utrecht.nl']);
        $this->app->bind(HostResolver::class, fn () => new class($this->addresses) implements HostResolver
        {
            public function __construct(private array $ips) {}

            public function addresses(string $host): array
            {
                return $this->ips;
            }

            public function txtRecords(string $host): array
            {
                return [];
            }
        });
        Sanctum::actingAs($this->owner);
    }

    private function connect(): WebshopConnection
    {
        $response = $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections', ['platform' => 'woocommerce',
            'store_url' => 'https://fietsen-utrecht.nl', 'consumer_key' => 'ck_SYNTHETIC_read_key', 'consumer_secret' => 'cs_SYNTHETIC_read_secret'])->assertCreated();
        $this->assertStringNotContainsString('cs_SYNTHETIC', $response->getContent());
        $this->assertStringNotContainsString('cs_SYNTHETIC', DB::table('webshop_connections')->value('credentials'));

        return WebshopConnection::where('public_id', $response->json('data.id'))->sole();
    }

    private function url(WebshopConnection $connection): string
    {
        return '/api/v1/workspaces/'.$this->shop->public_id.'/connections/'.$connection->public_id.'/test-api';
    }

    public function test_native_api_order_and_refunds_are_read_under_dns_pin_without_production_promotion(): void
    {
        $connection = $this->connect();
        Http::fake(function (Request $request, array $options) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(['fietsen-utrecht.nl:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertSame('Basic '.base64_encode('ck_SYNTHETIC_read_key:cs_SYNTHETIC_read_secret'), $request->header('Authorization')[0]);
            $this->assertStringNotContainsString('SYNTHETIC', $request->url());

            return str_contains($request->url(), '/refunds')
                ? Http::response([['id' => 10, 'amount' => '10.00']], 200, ['X-WP-TotalPages' => '1'])
                : Http::response($this->wooOrder());
        });
        $this->postJson($this->url($connection), ['order_id' => '4711'])->assertOk()->assertJsonPath('data.order_summary.payment_status', 'partially_refunded')
            ->assertJsonPath('data.production_verified', false)->assertJsonPath('data.state_changed', false)->assertDontSee('anna@example.nl')->assertDontSee('cs_SYNTHETIC');
        $this->assertSame('setup_required', $connection->fresh()->status->value);
        $this->assertDatabaseCount('webshop_orders', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $this->assertDatabaseCount('integration_outbox', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'integration.api_tested']);
    }

    public function test_nonpublic_or_mixed_dns_never_sends_credentials(): void
    {
        $connection = $this->connect();
        $this->addresses = ['93.184.216.34', '127.0.0.1'];
        Http::fake();
        $this->postJson($this->url($connection))->assertConflict()->assertJsonPath('error.code', 'provider_address_refused');
        Http::assertNothingSent();
    }

    public function test_store_identity_and_optional_credentials_are_validated_without_remote_calls(): void
    {
        Http::fake();
        $base = '/api/v1/workspaces/'.$this->shop->public_id.'/connections';
        $this->postJson($base, ['platform' => 'woocommerce', 'consumer_key' => 'ck_SYNTHETIC_read_key'])->assertUnprocessable();
        $this->postJson($base, ['platform' => 'woocommerce', 'store_url' => 'https://unrelated.nl', 'consumer_key' => 'ck_SYNTHETIC_read_key', 'consumer_secret' => 'cs_SYNTHETIC_read_secret'])->assertConflict();
        $legacy = $this->postJson($base, ['platform' => 'woocommerce'])->assertCreated()->json('data.id');
        $this->postJson($base.'/'.$legacy.'/test-api')->assertConflict()->assertJsonPath('error.code', 'provider_credentials_missing'); // URL is absent; no network.
        Http::assertNothingSent();
    }

    public function test_tenant_and_manage_permissions_block_probe_and_requests_are_throttled(): void
    {
        $connection = $this->connect();
        Http::fake(fn () => Http::response([]));
        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->postJson($this->url($connection))->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->url($connection))->assertNotFound();
        Http::assertNothingSent();
        Sanctum::actingAs($this->owner);
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson($this->url($connection));
            $this->assertSame(200, $response->status(), $response->getContent());
            $response->assertJsonPath('data.result.code', 'api_authenticated_no_orders');
        }
        $this->postJson($this->url($connection))->assertStatus(429);
        Http::assertSentCount(3);
    }

    public function test_redirect_authentication_and_schema_failures_do_not_expose_upstream_secrets(): void
    {
        $connection = $this->connect();
        $status = 302;
        Http::fake(fn () => Http::response(['private' => 'SECRET error'], $status, ['Location' => 'https://127.0.0.1/private']));
        $this->postJson($this->url($connection))->assertStatus(502)->assertJsonPath('error.code', 'provider_read_failed')->assertDontSee('SECRET');
        Http::assertSentCount(1);
        // Credential failure and invalid JSON use independent fake factories in separate tests below.
    }

    public function test_rate_limit_pause_survives_redis_numeric_string_representation(): void
    {
        $connection = $this->connect();
        // RedisStore preserves numeric cache values as strings. Emulate that storage boundary.
        Event::listen(KeyWritten::class, function ($event) {
            if (str_starts_with($event->key, 'commerce-api:') && str_ends_with($event->key, ':paused')) {
                Cache::getStore()->put($event->key, (string) $event->value, $event->seconds);
            }
        });
        Http::fake(['*' => Http::response(['private' => 'SECRET'], 429, ['Retry-After' => '9000'])]);
        $this->postJson($this->url($connection))->assertStatus(503)->assertHeader('Retry-After', '3600')->assertDontSee('SECRET');
        $this->postJson($this->url($connection))->assertStatus(503)->assertJsonPath('error.code', 'provider_circuit_open');
        Http::assertSentCount(1);
    }

    public function test_midflight_credential_replacement_cannot_record_a_success_for_stale_credentials(): void
    {
        $connection = $this->connect();
        Http::fake(function () use ($connection) {
            $connection->fresh()->forceFill(['credentials' => ['webhook_secret' => 'new-private-secret']])->save();

            return Http::response([]);
        });
        $this->postJson($this->url($connection))->assertConflict()->assertJsonPath('error.code', 'connection_changed');
        $this->assertDatabaseMissing('audit_events', ['action' => 'integration.api_tested']);
    }

    public function test_modified_order_polling_and_catalog_pages_are_read_only_and_bounded(): void
    {
        $connection = $this->connect();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/orders')) {
                $this->assertSame('true', $request['dates_are_gmt']);
                $this->assertSame('modified', $request['orderby']);
                $this->assertArrayHasKey('modified_after', $request->data());

                return Http::response([$this->wooOrder()], 200, ['X-WP-TotalPages' => '1']);
            }

            return Http::response([['id' => 7]], 200, ['X-WP-TotalPages' => '1']);
        });
        $connector = new WooCommerceConnector;
        $this->assertCount(1, iterator_to_array($connector->syncOrders($connection, now()->subHour())));
        $this->assertSame(7, $connector->readPage($connection, 'products')['data'][0]['id']);
        $this->assertSame(7, $connector->readPage($connection, 'coupons')['data'][0]['id']);
        Http::assertSentCount(3);
        $this->expectException(\InvalidArgumentException::class);
        app(WooCommerceApi::class)->get($connection, '../../private');
    }

    public function test_authentication_failure_has_a_safe_setup_error(): void
    {
        $connection = $this->connect();
        Http::fake(fn () => Http::response(['message' => 'SECRET provider detail'], 403));
        $this->postJson($this->url($connection))->assertConflict()->assertJsonPath('error.code', 'provider_authentication_failed')->assertDontSee('SECRET');
        $this->assertDatabaseHas('audit_events', ['action' => 'integration.api_test_failed', 'outcome' => 'failure']);
    }

    public function test_malformed_json_and_oversized_bodies_fail_closed(): void
    {
        $connection = $this->connect();
        $number = 0;
        Http::fake(function () use (&$number) {
            return Http::response(match (++$number) {
                1 => '{SECRET malformed', 2 => str_repeat('x', 1048577), default => [['id' => []]],
            });
        });
        $this->postJson($this->url($connection))->assertStatus(502)->assertJsonPath('error.code', 'provider_response_invalid')->assertDontSee('SECRET');
        $this->postJson($this->url($connection))->assertStatus(502)->assertJsonPath('error.code', 'provider_response_too_large');
        $this->postJson($this->url($connection))->assertStatus(502)->assertJsonPath('error.code', 'provider_response_invalid');
    }

    public function test_excessive_native_pagination_does_not_claim_a_complete_sync(): void
    {
        $connection = $this->connect();
        Http::fake(fn () => Http::response([$this->wooOrder()], 200, ['X-WP-TotalPages' => '100']));
        try {
            iterator_to_array((new WooCommerceConnector)->syncOrders($connection, now()->subDay()));
            $this->fail('Unbounded provider pagination accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_pagination_limit', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_connection_failures_trip_a_credential_bound_circuit_without_retries(): void
    {
        $connection = $this->connect();
        Http::fake(['*' => Http::failedConnection()]);
        for ($i = 0; $i < 3; $i++) {
            try {
                (new WooCommerceConnector)->testConnection($connection);
                $this->fail('Connection failure accepted.');
            } catch (ApiException $e) {
                $this->assertSame('provider_unreachable', $e->errorCode);
            }
        }
        try {
            (new WooCommerceConnector)->testConnection($connection);
            $this->fail('Open circuit ignored.');
        } catch (ApiException $e) {
            $this->assertSame('provider_circuit_open', $e->errorCode);
            $this->assertSame('300', $e->headers['Retry-After']);
        }
        Http::assertSentCount(3);
    }

    public function test_disconnected_and_unauthenticated_requests_never_contact_the_store(): void
    {
        $connection = $this->connect();
        $connection->forceFill(['status' => 'disconnected'])->save();
        Http::fake();
        $this->postJson($this->url($connection))->assertConflict()->assertJsonPath('error.code', 'connection_disconnected');
        $this->app['auth']->forgetGuards();
        $this->postJson($this->url($connection))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_store_url_rejects_embedded_credentials_encoded_paths_and_other_transports(): void
    {
        foreach (['http://fietsen-utrecht.nl', 'https://user:SECRET@fietsen-utrecht.nl', 'https://fietsen-utrecht.nl:444',
            'https://fietsen-utrecht.nl/%2e%2e', 'https://fietsen-utrecht.nl/?key=SECRET', 'https://fietsen-utrecht.nl/#SECRET', 'https://127.0.0.1', 'https://localhost'] as $url) {
            try {
                WooCommerceApi::baseUrl($url);
                $this->fail('Unsafe provider URL accepted.');
            } catch (ApiException $e) {
                $this->assertContains($e->errorCode, ['store_url_invalid', 'invalid_domain']);
                $this->assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }
    }

    public function test_www_uses_the_existing_normalized_ownership_domain_and_pins_the_actual_host(): void
    {
        $response = $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections', ['platform' => 'woocommerce',
            'store_url' => 'https://www.fietsen-utrecht.nl', 'consumer_key' => 'ck_SYNTHETIC_read_key', 'consumer_secret' => 'cs_SYNTHETIC_read_secret'])->assertCreated();
        $connection = WebshopConnection::where('public_id', $response->json('data.id'))->sole();
        Http::fake(function (Request $request, array $options) {
            $this->assertStringStartsWith('https://www.fietsen-utrecht.nl/', $request->url());
            $this->assertSame(['www.fietsen-utrecht.nl:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);

            return Http::response([]);
        });
        $this->postJson($this->url($connection))->assertOk();
    }
}
