<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\ShopwareConnector;
use App\Jobs\ProcessWebhookEvent;
use App\Models\AuditEvent;
use App\Models\ShopwareAppShop;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShopwareAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'SYNTHETIC_shopware_app_secret';

    private const SHOP_ID = 'sHoP1a2B3c4D5e6F';

    private const URL = 'https://fietsen-utrecht.nl';

    private const ORDER_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ORDER_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private WebshopConnection $c;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.shopbonus.example', 'integrations.shopware_app' => ['enabled' => true, 'name' => 'ShopbonusApp', 'secret' => self::APP_SECRET]]);
        $u = User::factory()->create();
        $shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        $this->c = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::Shopware6, ['app_registration' => true, 'store_url' => self::URL, 'sales_channel_id' => '0123456789abcdef0123456789abcdef'])[0];
        $this->secret = $this->handshake();
        Bus::fake([ProcessWebhookEvent::class]);
    }

    private function handshake(?string $current = null): string
    {
        $ts = now()->getTimestamp();
        $msg = sprintf('shop-id=%s&shop-url=%s&timestamp=%s', self::SHOP_ID, self::URL, $ts);
        $headers = ['shopware-app-signature' => hash_hmac('sha256', $msg, self::APP_SECRET)] + ($current === null ? [] : ['shopware-shop-signature' => hash_hmac('sha256', $msg, $current)]);
        $secret = $this->withHeaders($headers)->getJson('/api/v1/integrations/shopware/app/registration?'.http_build_query(['shop-id' => self::SHOP_ID, 'shop-url' => self::URL, 'timestamp' => $ts]))->assertOk()->json('secret');
        $body = json_encode(['apiKey' => 'SWIASYNTHETICAPIKEY01', 'secretKey' => 'SYNTHETIC_secret_key_value_0001', 'timestamp' => (string) $ts, 'shopUrl' => self::URL, 'shopId' => self::SHOP_ID]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_SHOPWARE_SHOP_SIGNATURE' => hash_hmac('sha256', $body, $secret)]
            + ($current === null ? [] : ['HTTP_SHOPWARE_SHOP_SIGNATURE_PREVIOUS' => hash_hmac('sha256', $body, $current)]);
        $this->call('POST', '/api/v1/integrations/shopware/app/registration/confirm', [], [], [], $server, $body)->assertNoContent();
        $this->flushHeaders();

        return $secret;
    }

    /** @param array<mixed> $payload */
    private function hook(string $event, array $payload, ?string $secret = null, ?int $ts = null, string $eventId = '0d1f6a4e-0000-4000-8000-000000000001', string $shopId = self::SHOP_ID): TestResponse
    {
        $body = json_encode(['data' => ['payload' => $payload, 'event' => $event], 'source' => ['url' => self::URL, 'appVersion' => '1.0.0', 'shopId' => $shopId, 'eventId' => $eventId], 'timestamp' => $ts ?? now()->getTimestamp()]);

        return $this->call('POST', '/api/v1/integrations/shopware/app/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_SHOPWARE_SHOP_SIGNATURE' => hash_hmac('sha256', $body, $secret ?? $this->secret), 'HTTP_SW_VERSION' => '6.7.0.0'], $body);
    }

    /** @return list<array<string,string>> */
    private function written(): array
    {
        return [['entity' => 'order', 'operation' => 'insert', 'primaryKey' => self::ORDER_A], ['entity' => 'order', 'operation' => 'update', 'primaryKey' => self::ORDER_B],
            ['entity' => 'order', 'operation' => 'update', 'primaryKey' => self::ORDER_A], ['entity' => 'order_line_item', 'operation' => 'insert', 'primaryKey' => 'cccccccccccccccccccccccccccccccc']];
    }

    public function test_signed_order_written_queues_one_id_only_lookup_per_order_and_dedupes_redelivery(): void
    {
        $this->hook('order.written', $this->written())->assertOk()->assertJsonPath('data.lookups_queued', 2);
        $events = WebhookEvent::where('connection_id', $this->c->id)->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['order_lookup'], $events->pluck('result.kind')->unique()->values()->all());
        $this->assertSame($this->c->fresh()->credential_generation, $events[0]->credential_generation);
        Bus::assertDispatchedTimes(ProcessWebhookEvent::class, 2);
        $connector = app(ShopwareConnector::class);
        $this->assertSame([self::ORDER_A, self::ORDER_B], $events->map(fn ($e) => $connector->notificationOrderId($this->c, (string) $e->payload))->all());

        $this->hook('order.written', $this->written())->assertOk()->assertJsonPath('data.lookups_queued', 0);
        $this->assertSame(2, WebhookEvent::count());
        $this->assertSame(2, (int) WebhookEvent::where('dedupe_key', 'like', '%'.self::ORDER_A)->value('delivery_count'));
    }

    public function test_payment_and_refund_state_events_queue_a_lookup_of_their_order(): void
    {
        // Only the order ID is used; amounts or states in the payload are never evidence.
        $paid = ['order' => ['id' => self::ORDER_A, 'amountTotal' => 9999.99], 'toPlace' => ['technicalName' => 'paid']];
        $this->hook('state_enter.order_transaction.state.paid', $paid, eventId: 'tx-paid')->assertOk()->assertJsonPath('data.lookups_queued', 1);
        $this->hook('state_enter.order_transaction.state.refunded_partially', ['order' => ['id' => self::ORDER_A]], eventId: 'tx-refund')
            ->assertOk()->assertJsonPath('data.lookups_queued', 1);
        $this->hook('state_enter.order_transaction.state.paid', $paid, eventId: 'tx-paid')->assertOk()->assertJsonPath('data.lookups_queued', 0);

        $events = WebhookEvent::orderBy('id')->get();
        $this->assertSame(['state_enter.order_transaction.state.paid', 'state_enter.order_transaction.state.refunded_partially'], $events->pluck('topic')->all());
        $connector = app(ShopwareConnector::class);
        $this->assertSame([self::ORDER_A, self::ORDER_A], $events->map(fn ($e) => $connector->notificationOrderId($this->c, (string) $e->payload))->all());
        $this->assertStringNotContainsString('9999', (string) $events[0]->payload);
        Bus::assertDispatchedTimes(ProcessWebhookEvent::class, 2);

        // Other states are acknowledged and ignored; a malformed order ID is refused.
        $this->hook('state_enter.order_transaction.state.in_progress', ['order' => ['id' => self::ORDER_A]], eventId: 'tx-other')->assertOk()->assertJsonPath('data.ignored', true);
        $this->hook('state_enter.order_transaction.state.paid', ['order' => ['id' => '../../etc']], eventId: 'tx-bad')->assertStatus(401);
        $this->assertSame(2, WebhookEvent::count());
    }

    public function test_forged_stale_unknown_or_malformed_webhooks_are_refused(): void
    {
        $this->hook('order.written', $this->written(), secret: 'SYNTHETIC_wrong_shop_secret')->assertStatus(401)->assertJsonPath('error.details.reason', 'signature_invalid');
        $this->hook('order.written', $this->written(), ts: now()->getTimestamp() - 3600)->assertStatus(401)->assertJsonPath('error.details.reason', 'event_invalid');
        $this->hook('order.written', $this->written(), shopId: 'unknownShop000001')->assertStatus(401);
        $this->hook('order.written', [['entity' => 'order', 'primaryKey' => '../../etc']])->assertStatus(401);
        $this->hook('order.written', array_map(fn ($i) => ['entity' => 'order', 'primaryKey' => str_pad(dechex($i), 32, '0', STR_PAD_LEFT)], range(1, 26)))->assertStatus(401);
        $this->assertSame(0, WebhookEvent::count());
        // Only the derived app hint is a lookup target; a module-bridge body never is.
        $this->assertNull(app(ShopwareConnector::class)->notificationOrderId($this->c, json_encode(['order_id' => self::ORDER_A])));
    }

    public function test_previous_secret_only_within_in_flight_allowance_after_rotation(): void
    {
        $old = $this->secret;
        $this->secret = $this->handshake($old);
        $this->hook('app.activated', [], secret: $old, eventId: 'e1')->assertOk();
        $this->travel(61)->seconds();
        $this->hook('app.activated', [], secret: $old, eventId: 'e2')->assertStatus(401);
        $this->hook('app.activated', [], eventId: 'e3')->assertOk();
    }

    public function test_app_deleted_revokes_binding_and_credentials(): void
    {
        $this->assertSame('bound', $this->c->fresh()->credential('app_registration'));
        $generation = $this->c->fresh()->credential_generation;
        $this->hook('app.deleted', ['keepUserData' => false])->assertOk()->assertJsonPath('data.revoked', true);
        $c = $this->c->fresh();
        $this->assertSame('pending', $c->credential('app_registration'));
        $this->assertNull($c->credential('client_id'));
        $this->assertSame($generation + 1, $c->credential_generation);
        $this->assertDatabaseCount('shopware_app_shops', 0);
        $this->assertSame(1, AuditEvent::where('action', 'integration.shopware_app_deleted')->count());
        // Later order hints for the removed shop are no longer authenticated.
        $this->hook('order.written', $this->written(), eventId: 'after-delete')->assertStatus(401);
    }

    public function test_keep_user_data_keeps_shop_secret_but_not_credentials_and_unbound_hints_are_ignored(): void
    {
        $this->hook('app.deleted', ['keepUserData' => true])->assertOk();
        $s = ShopwareAppShop::sole();
        $this->assertNull($s->api_credentials);
        $this->assertNull($s->connection_id);
        $this->assertSame($this->secret, $s->shop_secret);
        $this->hook('order.written', $this->written(), eventId: 'unbound')->assertOk()->assertJsonPath('data.ignored', true);
        $this->assertSame(0, WebhookEvent::count());
        // A new app-registration connection cannot bind a shop without credentials.
        $this->assertNull($this->c->fresh()->credential('client_id'));
    }
}
