<?php

namespace Tests\Feature\Admin;

use App\Enums\PlatformRole;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Services\Admin\StepUpCodes;
use App\Services\Answer24\OutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private WebshopConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $shop = $this->activeWebshop(User::factory()->create());
        $this->connection = WebshopConnection::create(['webshop_id' => $shop->id, 'platform' => 'prestashop', 'method' => 'webhook', 'status' => 'connected',
            'webhook_token' => 'private-token', 'credentials' => ['secret' => 'private-secret'], 'created_by' => $this->admin->id]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time(), StepUpCodes::SESSION_KEY => time()]);
    }

    private function event(): WebhookEvent
    {
        return WebhookEvent::create(['connection_id' => $this->connection->id, 'webshop_id' => $this->connection->webshop_id,
            'dedupe_key' => 'unique-event', 'status' => WebhookEventStatus::Failed, 'payload' => '{}', 'payload_hash' => hash('sha256', '{}'), 'received_at' => now()]);
    }

    public function test_monitoring_uses_actual_records_and_never_exposes_credentials_or_payloads(): void
    {
        $this->event();
        OutboxMessage::create(['target' => 'answer24', 'type' => 'answer24.contact_requested', 'payload' => ['phone' => 'private-phone'],
            'idempotency_key' => 'private-key', 'status' => 'failed', 'attempts' => 3, 'last_error' => 'http_503']);
        $this->asAdmin();
        $r = $this->getJson('/api/v1/admin/integrations')->assertOk()->assertJsonPath('data.0.failed_events', 1)->assertJsonPath('data.0.orders_today', 0);
        $this->assertStringNotContainsString('private-', $r->getContent());
        $r = $this->getJson('/api/v1/admin/answer24')->assertOk()->assertJsonPath('data.failed_dispatches', 1);
        $this->assertStringNotContainsString('private-', $r->getContent());
        $r = $this->getJson('/api/v1/admin/integrations/failed-events')->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('payload', $r->json('data.0'));
        $this->getJson('/api/v1/admin/integrations?per_page=101')->assertUnprocessable();
    }

    public function test_webhook_retry_queues_original_event_once_and_refuses_pruned_or_disconnected_events(): void
    {
        Bus::fake([ProcessWebhookEvent::class]);
        $event = $this->event();
        $this->asAdmin();
        $url = '/api/v1/admin/integrations/events/'.$event->public_id.'/retry';
        $this->postJson($url, ['reason' => 'Fixed technical mapping'])->assertAccepted();
        $this->postJson($url, ['reason' => 'Fixed technical mapping'])->assertConflict();
        Bus::assertDispatchedTimes(ProcessWebhookEvent::class, 1);
        $event->forceFill(['status' => 'failed', 'payload' => null, 'payload_pruned_at' => now()])->save();
        $this->postJson($url, ['reason' => 'Retry pruned payload'])->assertConflict();
        $event->forceFill(['payload' => '{}'])->save();
        $this->connection->forceFill(['status' => 'disconnected'])->save();
        $this->postJson($url, ['reason' => 'Retry disconnected'])->assertConflict();
    }

    public function test_outbox_retry_preserves_original_identity_and_does_not_enable_dispatch(): void
    {
        config(['shopbonus.answer24.dispatch_enabled' => false]);
        Http::fake();
        $message = OutboxMessage::create(['target' => 'answer24', 'type' => 'answer24.contact_requested', 'payload' => ['orders' => []],
            'idempotency_key' => 'original-key', 'status' => 'failed', 'attempts' => 10]);
        $this->asAdmin();
        $url = '/api/v1/admin/answer24/messages/'.$message->public_id.'/retry';
        $this->postJson($url, ['reason' => 'Provider fixed outage'])->assertAccepted();
        $this->postJson($url, ['reason' => 'Provider fixed outage'])->assertConflict();
        $this->assertSame('original-key', $message->fresh()->idempotency_key);
        $this->assertSame(10, $message->fresh()->attempts);
        app(OutboxDispatcher::class)->dispatch();
        Http::assertNothingSent();
    }

    public function test_financial_categories_are_scoped_to_admin_and_validate_input(): void
    {
        $this->getJson('/api/v1/admin/financial-oversight?category=payments')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/financial-oversight?category=payments')->assertForbidden();
        $this->asAdmin();
        foreach (['payments', 'funding_payments', 'affiliate_mismatches', 'failed_issuance', 'redemption_signals'] as $category) {
            $this->getJson('/api/v1/admin/financial-oversight?category='.$category)->assertOk()->assertJsonPath('meta.total', 0);
        }
        $this->getJson('/api/v1/admin/financial-oversight?category=payouts')->assertUnprocessable();
    }

    public function test_operation_routes_require_admin_and_step_up(): void
    {
        foreach (['integrations', 'integrations/failed-events', 'answer24'] as $route) {
            $this->getJson('/api/v1/admin/'.$route)->assertUnauthorized();
        }
        Sanctum::actingAs(User::factory()->create());
        foreach (['integrations', 'integrations/failed-events', 'answer24'] as $route) {
            $this->getJson('/api/v1/admin/'.$route)->assertForbidden();
        }
        foreach (['integrations/events/missing/retry', 'answer24/messages/missing/retry'] as $route) {
            $this->postJson('/api/v1/admin/'.$route, [])->assertForbidden();
            $this->asAdmin();
            $this->withSession([StepUpCodes::SESSION_KEY => 0]);
            $this->postJson('/api/v1/admin/'.$route, [])->assertStatus(423);
            $this->asAdmin();
            $this->postJson('/api/v1/admin/'.$route, ['reason' => 'Checked operation'])->assertNotFound();
            Sanctum::actingAs(User::factory()->create());
        }
    }
}
