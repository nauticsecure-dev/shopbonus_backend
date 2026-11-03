<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Enums\MembershipRole;
use App\Enums\WebhookEventStatus;
use App\Http\Resources\ConnectionResource;
use App\Integrations\ConnectionManager;
use App\Jobs\ProcessWebhookEvent;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

class ConnectionPauseTest extends TestCase
{
    use RefreshDatabase, SignsWebhooks;

    private User $owner;

    private Webshop $shop;

    private WebshopConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id]);
        [$this->connection] = app(ConnectionManager::class)->create($this->shop->memberships()->sole(), EcommercePlatform::WooCommerce, []);
        Sanctum::actingAs($this->owner);
    }

    private function url(string $action): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}/connections/{$this->connection->public_id}/{$action}";
    }

    public function test_pause_and_resume_are_idempotent_and_preserve_connection_authority(): void
    {
        $credentials = $this->connection->credentials;
        $generation = $this->connection->credential_generation;
        $status = $this->connection->status;
        $this->postJson($this->url('pause'))->assertOk()->assertJsonPath('data.status', $status->value);
        $paused = $this->connection->refresh()->paused_at;
        $this->assertNotNull($paused);
        $this->assertFalse($this->connection->acceptsData());
        $this->assertTrue($this->connection->status->receivesEvents());
        $this->postJson($this->url('pause'))->assertOk();
        $this->assertEquals($paused, $this->connection->refresh()->paused_at);
        $this->postJson($this->url('resume'))->assertOk()->assertJsonPath('data.paused_at', null);
        $this->postJson($this->url('resume'))->assertOk();
        $this->assertSame($credentials, $this->connection->refresh()->credentials);
        $this->assertSame($generation, $this->connection->credential_generation);
        $this->assertSame($status, $this->connection->status);
        $this->assertTrue($this->connection->acceptsData());
        $this->assertSame(1, AuditEvent::where('action', 'integration.paused')->count());
        $this->assertSame(1, AuditEvent::where('action', 'integration.resumed')->count());
    }

    public function test_other_workspaces_cannot_pause_or_resume_the_connection(): void
    {
        $other = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id]);
        foreach (['pause', 'resume'] as $action) {
            $this->postJson(str_replace($this->shop->public_id, $other->public_id, $this->url($action)))->assertNotFound();
        }
        $this->assertNull($this->connection->refresh()->paused_at);
    }

    public function test_nonmember_cannot_change_pause_state(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson($this->url('pause'))->assertNotFound();
        $this->postJson($this->url('resume'))->assertNotFound();
    }

    public function test_disconnected_connections_cannot_be_resumed(): void
    {
        app(ConnectionManager::class)->disconnect($this->connection);
        $this->postJson($this->url('resume'))->assertConflict()->assertJsonPath('error.code', 'connection_disconnected');
    }

    public function test_employee_without_manage_permission_is_denied(): void
    {
        $this->shop->memberships()->sole()->update(['role' => MembershipRole::Employee]);
        $this->postJson($this->url('pause'))->assertForbidden();
        $this->postJson($this->url('resume'))->assertForbidden();
    }

    public function test_paused_webhooks_require_authentication_then_ask_provider_to_retry(): void
    {
        $this->postJson($this->url('pause'))->assertOk();
        $path = parse_url(ConnectionResource::webhookUrl($this->connection), PHP_URL_PATH);
        $body = '{}';
        $headers = $this->wooHeaders($body, $this->connection->credential('webhook_secret'));
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(503)->assertJsonPath('error.code', 'connection_paused');
        $this->call('POST', $path, [], [], [], $server, '{"altered":true}')->assertUnauthorized();
        $this->assertSame(0, WebhookEvent::count());
        $this->postJson($this->url('sync/products'))->assertConflict()->assertJsonPath('error.code', 'connection_paused');
    }

    public function test_verified_queued_events_are_retained_while_paused_and_dispatched_on_resume(): void
    {
        $event = WebhookEvent::create(['connection_id' => $this->connection->id, 'webshop_id' => $this->shop->id,
            'credential_generation' => $this->connection->credential_generation, 'dedupe_key' => 'pause-fixture',
            'status' => WebhookEventStatus::Verified, 'payload' => '{}', 'payload_hash' => hash('sha256', '{}'),
            'source_method' => 'webhook', 'result' => ['kind' => 'order'], 'received_at' => now()]);
        $this->postJson($this->url('pause'))->assertOk();
        app()->call([new ProcessWebhookEvent($event->id), 'handle']);
        $this->assertSame(WebhookEventStatus::Verified, $event->refresh()->status);
        Queue::assertNotPushed(ProcessWebhookEvent::class);
        $this->postJson($this->url('resume'))->assertOk();
        Queue::assertPushed(ProcessWebhookEvent::class);
    }
}
