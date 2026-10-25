<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit\AuditIntegrity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessDenialAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_test')->group(function () {
            Route::get('orders/{order}', fn () => 'ok')->middleware('auth:sanctum');
            Route::get('admin-only', fn () => throw new AuthorizationException)->middleware('auth:sanctum');
        });
    }

    public function test_unauthenticated_api_request_is_audited(): void
    {
        $response = $this->getJson('/api/v1/_test/orders/123?email=jan@example.nl', ['X-Request-Id' => 'req-denied-401']);

        $response->assertStatus(401);
        $event = AuditEvent::sole();
        $this->assertSame('auth.unauthenticated', $event->action);
        $this->assertSame(AuditOutcome::Denied, $event->outcome);
        $this->assertSame(401, $event->http_status);
        $this->assertSame('req-denied-401', $event->request_id);
        $this->assertSame('GET', $event->http_method);
        // Route template only: no IDs or query string (which may carry PII).
        $this->assertSame('api/v1/_test/orders/{order}', $event->route);
        $this->assertSame(['error_code' => 'unauthenticated'], $event->metadata);
        $this->assertNotNull($event->ip_hash);
        $this->assertNull($event->actor_user_id);
    }

    public function test_forbidden_api_request_is_audited_with_actor(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/_test/admin-only')->assertStatus(403);

        $event = AuditEvent::sole();
        $this->assertSame('auth.forbidden', $event->action);
        $this->assertSame(403, $event->http_status);
        $this->assertSame($user->id, $event->actor_user_id);
    }

    public function test_successful_and_not_found_requests_are_not_audited_as_denials(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/nope')->assertNotFound();

        $this->assertSame(0, AuditEvent::count());
    }

    public function test_audit_storage_failure_does_not_change_the_response(): void
    {
        $this->mock(AuditIntegrity::class, function ($mock) {
            $mock->shouldReceive('record')->andThrow(new QueryException(
                'testing', 'insert into audit_events', [], new \PDOException('Simulated audit storage outage')
            ));
        });

        $this->getJson('/api/v1/_test/orders/1')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }
}
