<?php

namespace Tests\Feature\Integrations;

use App\Enums\ConnectionStatus;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\WooCommerceConnector;
use App\Jobs\ProcessWebhookEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Admin\OperationalRetries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

class CredentialLifecycleTest extends TestCase
{
    use RefreshDatabase, SignsWebhooks;

    private WebshopConnection $connection;

    private string $url;

    private string $path;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $shop = Webshop::factory()->withMember($owner)->create(['created_by' => $owner->id]);
        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/v1/workspaces/'.$shop->public_id.'/connections', ['platform' => 'woocommerce'])->assertCreated();
        $this->connection = WebshopConnection::where('public_id', $response->json('data.id'))->sole();
        $this->secret = $response->json('data.secrets.webhook_secret');
        $this->path = parse_url($response->json('data.setup.webhook_url'), PHP_URL_PATH);
        $this->url = '/api/v1/workspaces/'.$shop->public_id.'/connections/'.$this->connection->public_id;
        Bus::fake([ProcessWebhookEvent::class]);
    }

    private function receive(string $delivery = 'bound-1'): WebhookEvent
    {
        $body = json_encode($this->wooOrder(), JSON_THROW_ON_ERROR);
        $headers = $this->wooHeaders($body, $this->secret, $delivery);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }
        $response = $this->call('POST', $this->path, [], [], [], $server, $body)->assertAccepted();

        return WebhookEvent::where('public_id', $response->json('data.event_id'))->sole();
    }

    private function process(WebhookEvent $event): void
    {
        $this->app->call([new ProcessWebhookEvent($event->id), 'handle']);
    }

    public function test_rotation_discards_queued_old_events_before_order_effects_and_preserves_history(): void
    {
        $event = $this->receive();
        $this->assertSame(1, $event->credential_generation);
        $this->postJson($this->url.'/credentials')->assertOk()->assertJsonPath('data.status', 'setup_required');
        $this->process($event);
        $this->assertSame(WebhookEventStatus::Ignored, $event->fresh()->status);
        $this->assertSame('credential_generation_stale', $event->fresh()->error_code);
        $this->assertNotNull($event->fresh()->payload);
        $this->assertSame(0, WebshopOrder::count());
        $this->assertSame(ConnectionStatus::SetupRequired, $this->connection->fresh()->status);
        $this->getJson($this->url)->assertDontSee('credential_generation');
    }

    public function test_rotation_clears_connected_readiness_and_checklist_uses_only_current_generation(): void
    {
        $event = $this->receive();
        $this->process($event);
        $this->assertSame(ConnectionStatus::Limited, $this->connection->fresh()->status);
        $this->postJson($this->url.'/verify')->assertOk()->assertJsonPath('data.checks.order_received', true);
        $old = $this->connection->fresh();
        $this->postJson($this->url.'/credentials')->assertOk();
        $current = $this->connection->fresh();
        $this->assertSame(2, $current->credential_generation);
        $this->assertNull($current->connected_at);
        $this->assertNull($current->last_verified_order_at);
        $this->assertNull($current->last_event_at);
        $checks = app(ConnectionManager::class)->checklist($old);
        $this->assertSame('setup_required', $checks['status']);
        $this->assertFalse($checks['checks']['order_received']);
        $this->assertFalse($checks['checks']['mandatory_fields_present']);
        $this->assertSame(1, WebshopOrder::count());
        $this->assertSame(WebhookEventStatus::Processed, $event->fresh()->status);
    }

    public function test_disconnect_blocks_queued_processing_and_stale_credential_writer_cannot_reactivate(): void
    {
        $event = $this->receive();
        $stale = $this->connection->fresh();
        $this->deleteJson($this->url)->assertOk();
        $this->process($event);
        $this->assertSame(0, WebshopOrder::count());
        $this->assertSame(WebhookEventStatus::Ignored, $event->fresh()->status);
        try {
            app(ConnectionManager::class)->replaceCredentials($stale, []);
            $this->fail('Stale writer reactivated disconnected credentials.');
        } catch (ApiException $e) {
            $this->assertSame('connection_disconnected', $e->errorCode);
        }
        $this->assertNull($stale->fresh()->credentials);
        $this->assertSame(ConnectionStatus::Disconnected, $stale->fresh()->status);
    }

    public function test_legacy_unbound_and_rotated_events_cannot_be_retried_by_merchant_or_admin(): void
    {
        $event = $this->receive();
        foreach ([null, 0] as $generation) {
            $event->forceFill(['status' => WebhookEventStatus::Failed, 'credential_generation' => $generation])->save();
            $this->postJson($this->url.'/events/'.$event->public_id.'/retry')->assertConflict()->assertJsonPath('error.code', 'event_not_retryable');
            try {
                app(OperationalRetries::class)->webhook($event, 'Synthetic stale retry');
                $this->fail('Administrative retry accepted unbound credentials.');
            } catch (ApiException $e) {
                $this->assertSame('event_not_retryable', $e->errorCode);
            }
            $event->forceFill(['status' => WebhookEventStatus::Verified])->save();
            $this->process($event);
            $this->assertSame(WebhookEventStatus::Ignored, $event->fresh()->status);
        }
        $this->assertSame(0, WebshopOrder::count());
    }

    public function test_rotation_between_signature_verification_and_persistence_rejects_old_proof(): void
    {
        $connector = Mockery::mock(WooCommerceConnector::class)->makePartial();
        $connector->shouldReceive('verifyIncomingEvent')->once()->andReturnUsing(function ($connection, $request) {
            $verified = (new WooCommerceConnector)->verifyIncomingEvent($connection, $request);
            app(ConnectionManager::class)->replaceCredentials($connection->fresh(), []);

            return $verified;
        });
        $this->app->instance(WooCommerceConnector::class, $connector);
        $body = json_encode($this->wooOrder(), JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->wooHeaders($body, $this->secret) as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }
        $this->call('POST', $this->path, [], [], [], $server, $body)->assertConflict()->assertJsonPath('error.code', 'connection_changed');
        $this->assertSame(0, WebhookEvent::count());
        $this->assertNull($this->connection->fresh()->last_event_at);
    }

    public function test_provider_dedupe_survives_rotation_and_only_new_delivery_supplies_current_proof(): void
    {
        $event = $this->receive('permanent-delivery');
        $response = $this->postJson($this->url.'/credentials')->assertOk();
        $this->secret = $response->json('data.secrets.webhook_secret');
        $body = json_encode($this->wooOrder(), JSON_THROW_ON_ERROR);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->wooHeaders($body, $this->secret, 'permanent-delivery') as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }
        $this->call('POST', $this->path, [], [], [], $server, $body)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertSame(1, WebhookEvent::count());
        $this->postJson($this->url.'/verify')->assertOk()->assertJsonPath('data.checks.event_received', false);
        $this->process($event);
        $new = $this->receive('new-generation-delivery');
        $this->assertSame(2, $new->credential_generation);
        $this->process($new);
        $this->assertSame(1, WebshopOrder::count());
        $this->postJson($this->url.'/verify')->assertOk()->assertJsonPath('data.checks.order_received', true);
    }
}
