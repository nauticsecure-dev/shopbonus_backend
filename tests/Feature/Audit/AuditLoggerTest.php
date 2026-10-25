<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditIntegrity;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private function logger(): AuditLogger
    {
        return $this->app->make(AuditLogger::class);
    }

    public function test_records_event_with_context_actor_resource_and_redacted_metadata(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Context::add('request_id', 'req-audit-0001');
        Context::add(AuditLogger::CONTEXT_WEBSHOP_ID, 77);

        $event = $this->logger()->record(
            action: 'workspace.member_added',
            category: AuditCategory::Workspace,
            resource: $user,
            metadata: ['role' => 'staff', 'email' => 'jan@example.nl'],
        );

        $fresh = AuditEvent::findOrFail($event->id);
        $this->assertSame('req-audit-0001', $fresh->request_id);
        $this->assertSame(AuditCategory::Workspace, $fresh->category);
        $this->assertSame(AuditOutcome::Success, $fresh->outcome);
        $this->assertSame($user->id, $fresh->actor_user_id);
        $this->assertSame($user->id, $fresh->real_actor_user_id);
        $this->assertSame(77, $fresh->webshop_id);
        $this->assertSame($user->getMorphClass(), $fresh->resource_type);
        $this->assertSame((string) $user->id, $fresh->resource_id);
        $this->assertSame(['role' => 'staff', 'email' => '[REDACTED]'], $fresh->metadata);
    }

    public function test_real_actor_differs_during_support_session(): void
    {
        $merchant = User::factory()->create();
        $admin = User::factory()->create();
        $this->actingAs($merchant);
        Context::add(AuditLogger::CONTEXT_REAL_ACTOR_ID, $admin->id);

        $event = $this->logger()->record('order.viewed', category: AuditCategory::Order);

        $this->assertSame($merchant->id, $event->actor_user_id);
        $this->assertSame($admin->id, $event->real_actor_user_id);
    }

    public function test_outside_http_request_has_no_method_route_or_ip(): void
    {
        $event = $this->logger()->record('system.job_run');

        $this->assertNull($event->http_method);
        $this->assertNull($event->route);
        $this->assertNull($event->ip_hash);
        $this->assertNull($event->actor_user_id);
    }

    public function test_ip_is_stored_as_keyed_hash_not_plain_address(): void
    {
        $hash = $this->logger()->hashIp('203.0.113.9');

        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString('203.0.113.9', $hash);
        $this->assertSame($hash, $this->logger()->hashIp('203.0.113.9'));
        $this->assertNotSame($hash, $this->logger()->hashIp('203.0.113.10'));
    }

    public function test_record_throws_but_record_safely_swallows_storage_failure(): void
    {
        $this->mock(AuditIntegrity::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new QueryException(
                'testing', 'insert into audit_events', [], new \PDOException('Simulated audit storage outage')
            ));
        });
        Log::spy();

        $this->assertNull($this->logger()->recordSafely('auth.forbidden'));
        Log::shouldHaveReceived('error')->once();

        $this->expectException(QueryException::class);
        $this->logger()->record('auth.forbidden');
    }
}
