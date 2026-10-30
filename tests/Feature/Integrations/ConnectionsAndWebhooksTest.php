<?php

namespace Tests\Feature\Integrations;

use App\Enums\ConnectionStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WebhookEventStatus;
use App\Events\OrderEventVerified;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

/**
 * B04 connections and webhook intake (Trello #1).
 */
class ConnectionsAndWebhooksTest extends TestCase
{
    use RefreshDatabase, SignsWebhooks;

    private User $owner;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id]);
        Sanctum::actingAs($this->owner);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}{$suffix}";
    }

    /** @return array{0: WebshopConnection, 1: string, 2: string} connection, secret, webhook path */
    private function connect(string $platform = 'woocommerce', array $input = []): array
    {
        $response = $this->postJson($this->url('/connections'), ['platform' => $platform, ...$input])->assertCreated();
        $connection = WebshopConnection::where('public_id', $response->json('data.id'))->sole();
        $secret = (string) collect($response->json('data.secrets'))->first();
        $path = parse_url($response->json('data.setup.webhook_url'), PHP_URL_PATH);

        return [$connection, $secret, $path];
    }

    private function postWebhook(string $path, string $body, array $headers): TestResponse
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';
        $server['HTTP_ACCEPT'] = 'application/json';

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    public function test_platform_catalogue_is_honest_about_availability_and_verification(): void
    {
        $platforms = collect($this->getJson('/api/v1/integrations/platforms')->assertOk()->json('data'))->keyBy('platform');

        $this->assertTrue($platforms['woocommerce']['available']);
        $this->assertTrue($platforms['shopify']['available']);
        $this->assertTrue($platforms['bigcommerce']['available']);
        $this->assertTrue($platforms['ecwid']['available']);
        $this->assertTrue($platforms['prestashop']['available']);
        $this->assertTrue($platforms['wix']['available']);
        $this->assertNull($platforms['wix']['blocked_reason']);
        $this->assertSame(['unverified'], $platforms->pluck('verification')->unique()->values()->all());
    }

    public function test_card1_e2e2_unsupported_platforms_cannot_be_connected(): void
    {
        foreach (['lightspeed_e', 'mijnwebwinkel'] as $platform) {
            $this->postJson($this->url('/connections'), ['platform' => $platform])
                ->assertStatus(409)->assertJsonPath('error.code', 'platform_not_available');
        }
        $this->assertSame(0, WebshopConnection::count());
    }

    public function test_create_shows_secret_once_and_starts_as_setup_required(): void
    {
        $response = $this->postJson($this->url('/connections'), ['platform' => 'woocommerce'])->assertCreated()
            ->assertJsonPath('data.status', 'setup_required')
            ->assertJsonPath('data.setup.format', 'woocommerce-webhooks');
        $secret = $response->json('data.secrets.webhook_secret');
        $this->assertSame(48, strlen($secret));

        $id = $response->json('data.id');
        $show = $this->getJson($this->url("/connections/{$id}"))->assertOk();
        $show->assertJsonMissingPath('data.secrets');
        $this->assertStringNotContainsString($secret, $show->getContent());
        $this->assertStringNotContainsString($secret, $this->getJson($this->url('/connections'))->getContent());

        // Encrypted at rest.
        $this->assertStringNotContainsString($secret, (string) DB::table('webshop_connections')->value('credentials'));
        $this->assertSame(1, AuditEvent::where('action', 'integration.connection_created')->count());

        $this->postJson($this->url('/connections'), ['platform' => 'woocommerce'])->assertStatus(409)->assertJsonPath('error.code', 'connection_exists');
    }

    public function test_card1_e2e1_woocommerce_verified_order_requires_quality_gates_and_is_processed_once(): void
    {
        Event::fake([OrderEventVerified::class]);
        [$connection, $secret, $path] = $this->connect();
        $body = json_encode($this->wooOrder());

        $this->postWebhook($path, $body, $this->wooHeaders($body, $secret, 'delivery-1'))
            ->assertStatus(202)->assertJsonPath('data.received', true)->assertJsonPath('data.duplicate', false);

        $connection->refresh();
        $this->assertSame(ConnectionStatus::Limited, $connection->status);
        $this->assertNull($connection->connected_at);
        $event = WebhookEvent::sole();
        $this->assertSame(WebhookEventStatus::Processed, $event->status);
        $this->assertTrue($event->result['complete']);
        $this->assertSame(5995, $event->result['amount_minor']);
        Event::assertDispatchedTimes(OrderEventVerified::class, 1);

        // Card #1 E2E 5: retried delivery → acknowledged, stored once, not reprocessed.
        $this->postWebhook($path, $body, $this->wooHeaders($body, $secret, 'delivery-1'))
            ->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(2, WebhookEvent::sole()->delivery_count);
        Event::assertDispatchedTimes(OrderEventVerified::class, 1);

        // Payload is encrypted at rest.
        $this->assertStringNotContainsString('anna@example.nl', (string) DB::table('webhook_events')->value('payload'));
    }

    public function test_forged_or_tampered_webhooks_are_rejected_and_never_stored(): void
    {
        [$connection, $secret, $path] = $this->connect();
        $body = json_encode($this->wooOrder());

        $this->postWebhook($path, $body, $this->wooHeaders($body, 'attacker-guess'))
            ->assertStatus(401)->assertJsonPath('error.code', 'webhook_rejected')->assertJsonPath('error.details.reason', 'invalid_signature');
        $this->postWebhook($path, str_replace('59.95', '0.01', $body), $this->wooHeaders($body, $secret))->assertStatus(401);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(ConnectionStatus::SetupRequired, $connection->fresh()->status);
        $this->assertSame('webhook_invalid_signature', $connection->fresh()->last_error_code);
        // Rejections are audited once per minute per reason, not per request.
        $this->assertSame(1, AuditEvent::where('action', 'integration.webhook_rejected')->count());
    }

    public function test_unknown_tokens_wrong_platform_and_disconnected_connections_are_404(): void
    {
        [$connection, $secret, $path] = $this->connect();
        $body = json_encode($this->wooOrder());
        $headers = $this->wooHeaders($body, $secret);

        $this->postWebhook('/api/v1/webhooks/woocommerce/'.str_repeat('x', 40), $body, $headers)->assertNotFound();
        $this->postWebhook(str_replace('/woocommerce/', '/shopify/', $path), $body, $headers)->assertNotFound();
        $this->postWebhook(str_replace('/woocommerce/', '/myspace/', $path), $body, $headers)->assertNotFound();

        $this->deleteJson($this->url("/connections/{$connection->public_id}"))->assertOk()->assertJsonPath('data.status', 'disconnected');
        $this->assertNull($connection->fresh()->credentials);
        $this->postWebhook($path, $body, $headers)->assertNotFound();
    }

    public function test_ping_moves_setup_required_to_testing_but_never_to_connected(): void
    {
        [$connection, $secret, $path] = $this->connect();

        $this->postWebhook($path, 'webhook_id=17', $this->wooHeaders('webhook_id=17', $secret, '', null))->assertStatus(202);

        $this->assertSame(ConnectionStatus::Testing, $connection->fresh()->status);
    }

    public function test_card1_e2e3_shopify_redacted_order_gives_limited_not_connected(): void
    {
        [$connection, , $path] = $this->connect('shopify', ['shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'shpss_merchant_secret_1']);
        $body = json_encode($this->shopifyOrder(['email' => null, 'customer' => ['first_name' => null, 'email' => null]]));

        $this->postWebhook($path, $body, $this->shopifyHeaders($body, 'shpss_merchant_secret_1'))->assertStatus(202);

        $connection->refresh();
        $this->assertSame(ConnectionStatus::Limited, $connection->status);
        $this->assertSame(['additional_integration_required'], $connection->limitations);

        $checklist = $this->postJson($this->url("/connections/{$connection->public_id}/verify"))->assertOk()->json('data');
        $this->assertFalse($checklist['checks']['mandatory_fields_present']);
        $this->assertSame('additional_integration_required', $checklist['next_action']);
        $this->assertEqualsCanonicalizing(['first_name', 'email'], $checklist['missing_fields']);

        // A later complete webhook fixes mapping, but cannot bypass native quality gates.
        $full = json_encode($this->shopifyOrder());
        $this->postWebhook($path, $full, $this->shopifyHeaders($full, 'shpss_merchant_secret_1', 'w-2'))->assertStatus(202);
        $this->assertSame(ConnectionStatus::Limited, $connection->fresh()->status);
    }

    public function test_shopify_connect_validates_merchant_supplied_credentials(): void
    {
        $this->postJson($this->url('/connections'), ['platform' => 'shopify', 'shop_domain' => 'evil.com', 'signing_secret' => 'short'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['shop_domain', 'signing_secret']]]);
    }

    public function test_generic_module_webhook_and_secret_rotation(): void
    {
        [$connection, $old, $path] = $this->connect('prestashop');
        $body = json_encode($this->genericOrder());
        $this->postWebhook($path, $body, $this->genericHeaders($body, $old, 'e-1'))->assertStatus(202);
        $this->assertSame(ConnectionStatus::Limited, $connection->fresh()->status);

        $new = $this->postJson($this->url("/connections/{$connection->public_id}/credentials"))->assertOk()->json('data.secrets.signing_secret');
        $this->assertNotSame($old, $new);

        $this->postWebhook($path, $body, $this->genericHeaders($body, $old, 'e-2'))->assertStatus(401);
        $this->postWebhook($path, $body, $this->genericHeaders($body, $new, 'e-3'))->assertStatus(202);
        $this->assertSame(1, AuditEvent::where('action', 'integration.credentials_replaced')->count());
    }

    public function test_replayed_old_signed_request_is_rejected(): void
    {
        [, $secret, $path] = $this->connect('other');
        $body = json_encode($this->genericOrder());

        $this->postWebhook($path, $body, $this->genericHeaders($body, $secret, 'e-1', 'order.paid', time() - 600))
            ->assertStatus(401)->assertJsonPath('error.details.reason', 'timestamp_out_of_tolerance');
    }

    public function test_event_history_is_sanitised(): void
    {
        [$connection, $secret, $path] = $this->connect();
        $body = json_encode($this->wooOrder());
        $this->postWebhook($path, $body, $this->wooHeaders($body, $secret));

        $events = $this->getJson($this->url("/connections/{$connection->public_id}/events"))->assertOk();
        $this->assertSame('order.created', $events->json('data.0.topic'));
        $this->assertStringNotContainsString('anna@example.nl', $events->getContent());
        $this->assertStringNotContainsString('Anna', $events->getContent());
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        [$connection] = $this->connect();

        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->getJson($this->url('/connections'))->assertForbidden()->assertJsonPath('error.details.required_permission', 'integrations.view');

        $other = User::factory()->create();
        $otherShop = Webshop::factory()->withMember($other)->create(['created_by' => $other->id]);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/workspaces/{$otherShop->public_id}/connections/{$connection->public_id}")->assertNotFound();
        $this->deleteJson("/api/v1/workspaces/{$otherShop->public_id}/connections/{$connection->public_id}")->assertNotFound();
    }

    public function test_secrets_never_reach_the_logs(): void
    {
        $logPath = storage_path('logs/b04-'.uniqid().'.log');
        config(['logging.channels.single.path' => $logPath, 'logging.default' => 'single']);
        Log::forgetChannel('single');

        [, $secret, $path] = $this->connect();
        $body = json_encode($this->wooOrder());
        $this->postWebhook($path, $body, $this->wooHeaders($body, 'wrong'));
        $this->postWebhook($path, $body, $this->wooHeaders($body, $secret));

        $logs = file_exists($logPath) ? (string) file_get_contents($logPath) : '';
        @unlink($logPath);
        $this->assertStringNotContainsString($secret, $logs);
        $this->assertStringNotContainsString($secret, json_encode(AuditEvent::all()->toArray()));
    }

    public function test_payload_retention_prune(): void
    {
        [, $secret, $path] = $this->connect();
        $body = json_encode($this->wooOrder());
        $this->postWebhook($path, $body, $this->wooHeaders($body, $secret));

        $this->travel(15)->days();
        $this->artisan('shopbonus:prune-webhook-payloads')->assertSuccessful();

        $event = WebhookEvent::sole();
        $this->assertNull($event->payload);
        $this->assertNotNull($event->payload_pruned_at);
        $this->assertSame(64, strlen($event->payload_hash)); // dedupe history stays
    }

    public function test_oversized_bodies_are_rejected(): void
    {
        [, $secret, $path] = $this->connect();
        $body = str_repeat('a', 1_048_577);

        $this->postWebhook($path, $body, $this->wooHeaders($body, $secret))->assertStatus(401)
            ->assertJsonPath('error.details.reason', 'payload_too_large');
    }

    public function test_signed_shopify_uninstall_revokes_credentials_and_stale_deliveries_cannot_process(): void
    {
        [$c, , $path] = $this->connect('shopify', ['shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'shpss_merchant_secret_1', 'access_token' => 'SYNTHETIC_revoked_app_token']);
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
        Http::fake(fn () => Http::response([], 401));
        $generation = $c->credential_generation;
        $body = json_encode(['id' => 1]);
        $headers = $this->shopifyHeaders($body, 'shpss_merchant_secret_1', 'SYNTHETIC-uninstall');
        $headers['X-Shopify-Topic'] = 'app/uninstalled';
        $this->postWebhook($path, $body, $headers)->assertStatus(202);
        $this->assertSame(ConnectionStatus::Disconnected, $c->fresh()->status);
        $this->assertSame($generation + 1, $c->fresh()->credential_generation);
        $this->assertNull($c->fresh()->credentials);
        $this->postWebhook($path, $body, $headers)->assertNotFound();
        $this->assertSame(0, WebshopOrder::count());
    }

    public function test_replayed_uninstall_cannot_revoke_a_still_valid_current_grant(): void
    {
        [$c, , $path] = $this->connect('shopify', ['shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'shpss_merchant_secret_1', 'access_token' => 'SYNTHETIC_current_app_token']);
        $this->shop->forceFill(['verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt'])->save();
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
        Http::fake(fn () => Http::response(['data' => ['shop' => ['myshopifyDomain' => 'fietsen.myshopify.com', 'primaryDomain' => ['host' => 'fietsen-utrecht.nl']], 'currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders']]]]], 200, ['X-Shopify-API-Version' => '2026-10']));
        $body = json_encode(['id' => 1]);
        $headers = $this->shopifyHeaders($body, 'shpss_merchant_secret_1', 'SYNTHETIC-old-uninstall');
        $headers['X-Shopify-Topic'] = 'app/uninstalled';
        $this->postWebhook($path, $body, $headers)->assertStatus(202);
        $this->assertSame(ConnectionStatus::SetupRequired, $c->fresh()->status);
        $this->assertNotNull($c->fresh()->credential('access_token'));
        $this->assertSame('uninstall_current_grant_still_valid', $c->events()->sole()->error_code);
    }
}
