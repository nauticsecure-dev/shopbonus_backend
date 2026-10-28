<?php

namespace Tests\Feature\Reliability;

use App\Enums\PlatformRole;
use App\Models\FinancialReconciliationRun;
use App\Models\User;
use App\Services\Admin\StepUpCodes;
use App\Services\Reliability\FinancialReconciliation;
use App\Services\Reliability\Operations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationsAndReconciliationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_run_identity_replays_and_reports_are_private_and_bounded(): void
    {
        $this->getJson('/api/v1/admin/reconciliation')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/reconciliation')->assertForbidden();
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        Sanctum::actingAs($admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time(), StepUpCodes::SESSION_KEY => time()]);
        $key = (string) Str::uuid();
        $id = $this->postJson('/api/v1/admin/reconciliation', ['request_key' => $key])->assertStatus(202)->json('data.run');
        $this->postJson('/api/v1/admin/reconciliation', ['request_key' => $key])->assertStatus(202)->assertJsonPath('data.run', $id);
        $this->assertDatabaseCount('financial_reconciliation_runs', 1);
        $this->getJson('/api/v1/admin/reconciliation?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/admin/reconciliation/'.$id)->assertOk()->assertJsonPath('data.scope_version', 2)->assertDontSee('run_key');
        $this->postJson('/api/v1/admin/reconciliation/'.$id.'/resume', ['reason' => 'Investigated storage failure'])->assertConflict();
        $this->postJson('/api/v1/admin/reconciliation', ['request_key' => (string) Str::uuid()])->assertStatus(429);
        $this->travel(61)->seconds();
        FinancialReconciliationRun::sole()->forceFill(['status' => 'failed'])->save();
        $this->postJson('/api/v1/admin/reconciliation/'.$id.'/resume', ['reason' => 'Investigated storage failure'])->assertStatus(202);
        $this->getJson('/api/v1/admin/reconciliation/missing')->assertNotFound();
        $this->withSession([StepUpCodes::SESSION_KEY => 0]);
        $this->postJson('/api/v1/admin/reconciliation', ['request_key' => (string) Str::uuid()])->assertStatus(423);
    }

    public function test_operations_detects_ready_queue_stall_and_absence_never_means_healthy(): void
    {
        config(['queue.default' => 'database']);
        $this->assertSame('UNKNOWN', app(Operations::class)->report()['components']['backups']['status']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(10)->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp()]);
        $report = app(Operations::class)->report();
        $this->assertSame('CRITICAL', $report['components']['queue']['status']);
        $this->assertGreaterThanOrEqual(600, $report['components']['queue']['ready_wait_seconds']);
        $this->assertSame('UNKNOWN', $report['components']['scheduler']['status']);
        app(Operations::class)->observe();
        $this->assertDatabaseHas('security_incidents', ['route' => 'operations']);
        $this->assertSame('HEALTHY', app(Operations::class)->report()['components']['scheduler']['status']);
        $this->travel(6)->minutes();
        $this->assertSame('CRITICAL', app(Operations::class)->report()['components']['scheduler']['status']);
    }

    public function test_legacy_wallet_only_run_never_acquires_new_source_coverage(): void
    {
        $run = FinancialReconciliationRun::create(['run_key' => 'legacy', 'wallet_watermark' => 0]);
        $runner = app(FinancialReconciliation::class);
        $run = $runner->advance($run);
        $this->assertSame(1, $run->scope_version);
        $this->assertSame('UNKNOWN', $runner->summary($run)['coverage']['affiliate']);
    }
}
