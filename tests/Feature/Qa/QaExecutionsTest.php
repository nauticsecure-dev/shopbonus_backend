<?php

namespace Tests\Feature\Qa;

use App\Enums\PlatformRole;
use App\Jobs\ExecuteQaStaging;
use App\Models\QaExecutionRequest;
use App\Models\User;
use App\Services\Qa\QaExecutions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class QaExecutionsTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $qa;

    private array $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Bus::fake([ExecuteQaStaging::class]);
        $this->qa = User::factory()->create();
        $this->qa->platformRoles()->create(['role' => PlatformRole::QaTester, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->plan = ['deployment_sha' => str_repeat('a', 40), 'trigger' => 'nightly', 'request_key' => (string) Str::uuid()];
        config(['qa.runner_enabled' => true, 'qa.release_sha' => $this->plan['deployment_sha'], 'qa.execution_queue_connection' => 'qa-staging']);
    }

    private function asQa(bool $password = true): void
    {
        Sanctum::actingAs($this->qa);
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/', 'X-CSRF-TOKEN' => str_repeat('c', 40)]);
        $this->withSession(['auth.password_confirmed_at' => $password ? time() : 0, '_token' => str_repeat('c', 40)]);
    }

    public function test_anonymous_developer_consumer_and_missing_step_up_cannot_execute(): void
    {
        $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/qa/executions')->assertForbidden();
        $developer = User::factory()->create();
        $developer->platformRoles()->create(['role' => PlatformRole::Developer, 'granted_via' => 'console', 'granted_at' => now()]);
        Sanctum::actingAs($developer);
        $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertForbidden();
        $this->asQa(false);
        $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertStatus(423);
        Bus::assertNotDispatched(ExecuteQaStaging::class);
    }

    public function test_production_wrong_build_disabled_and_sync_execution_are_refused(): void
    {
        $this->asQa();
        $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertConflict();
        $this->app['env'] = 'staging';
        config(['qa.release_sha' => str_repeat('b', 40)]);
        $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertConflict();
        config(['qa.release_sha' => $this->plan['deployment_sha'], 'qa.runner_enabled' => false]);
        $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertConflict();
        config(['qa.runner_enabled' => true, 'qa.execution_queue_connection' => 'sync']);
        $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertConflict();
        Bus::assertNotDispatched(ExecuteQaStaging::class);
    }

    public function test_request_replay_queues_once_and_conflicting_intent_is_rejected(): void
    {
        $this->asQa();
        $this->app['env'] = 'staging';
        $first = $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertStatus(202)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.release_approved', false);
        $this->postJson('/api/v1/admin/qa/executions', $this->plan)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/admin/qa/executions', array_replace($this->plan, ['trigger' => 'weekly']))->assertConflict();
        $this->getJson('/api/v1/admin/qa/executions')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, QaExecutionRequest::count());
        Bus::assertDispatchedTimes(ExecuteQaStaging::class, 1);
    }

    public function test_worker_rechecks_revocation_and_redeployment_before_running(): void
    {
        $this->app['env'] = 'staging';
        $service = app(QaExecutions::class);
        $row = $service->request($this->qa, $this->plan['deployment_sha'], 'nightly', $this->plan['request_key']);
        $this->qa->platformRoles()->update(['revoked_at' => now()]);
        Artisan::shouldReceive('call')->never();
        (new ExecuteQaStaging($row->id))->handle($service);
        $this->assertSame('blocked', $row->fresh()->status);
        $this->assertFalse($row->fresh()->evidence_complete);
    }

    public function test_worker_executes_combined_gate_once_and_never_grants_release_approval(): void
    {
        $this->app['env'] = 'staging';
        $service = app(QaExecutions::class);
        $row = $service->request($this->qa, $this->plan['deployment_sha'], 'weekly', $this->plan['request_key']);
        Artisan::shouldReceive('call')->once()->with('shopbonus:qa-staging-run', ['trigger' => 'weekly', '--sha' => $row->deployment_sha])->andReturn(1);
        $job = new ExecuteQaStaging($row->id);
        $job->handle($service);
        $job->handle($service);
        $this->assertSame('blocked', $row->fresh()->status);
        $this->assertFalse($row->fresh()->evidence_complete);
        $this->assertFalse($service->present($row->fresh())['release_approved']);
    }

    public function test_successful_combined_gate_records_completion_without_owner_release_approval(): void
    {
        $this->app['env'] = 'staging';
        $service = app(QaExecutions::class);
        $row = $service->request($this->qa, $this->plan['deployment_sha'], 'nightly', $this->plan['request_key']);
        Artisan::shouldReceive('call')->once()->with('shopbonus:qa-staging-run', ['trigger' => 'nightly', '--sha' => $row->deployment_sha])->andReturn(0);
        (new ExecuteQaStaging($row->id))->handle($service);
        $this->assertSame('completed', $row->fresh()->status);
        $this->assertTrue($row->fresh()->evidence_complete);
        $this->assertFalse($service->present($row->fresh())['release_approved']);
    }

    public function test_validation_rejects_arbitrary_commands_and_nonexact_shas(): void
    {
        $this->asQa();
        $this->postJson('/api/v1/admin/qa/executions', array_replace($this->plan, ['trigger' => 'shell']))->assertUnprocessable();
        $this->postJson('/api/v1/admin/qa/executions', array_replace($this->plan, ['deployment_sha' => str_repeat('a', 41)]))->assertUnprocessable();
        Bus::assertNotDispatched(ExecuteQaStaging::class);
    }
}
