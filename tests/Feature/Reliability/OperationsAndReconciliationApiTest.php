<?php

namespace Tests\Feature\Reliability;

use App\Enums\PlatformRole;
use App\Models\FinancialReconciliationRun;
use App\Models\User;
use App\Services\Admin\StepUpCodes;
use App\Services\Reliability\FinancialReconciliation;
use App\Services\Reliability\Operations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationsAndReconciliationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_ready_jobs_are_separate_from_delayed_work_and_creation_age_is_not_ready_wait(): void
    {
        config(['queue.default' => 'sync', 'reliability.monitored_connections' => ['redis' => ['financial']]]);
        $redis = \Mockery::mock(RedisQueue::class);
        $redis->shouldReceive('size')->with('financial')->once()->andReturn(3);
        $redis->shouldReceive('pendingSize')->with('financial')->once()->andReturn(1);
        $redis->shouldReceive('creationTimeOfOldestPendingJob')->with('financial')->once()->andReturn(now()->subHour()->getTimestamp());
        Queue::shouldReceive('connection')->with('redis')->andReturn($redis);
        $queue = app(Operations::class)->report()['components']['queue']['connections']['redis']['queues']['financial'];
        $this->assertSame(1, $queue['ready_jobs']);
        $this->assertGreaterThanOrEqual(3600, $queue['oldest_ready_job_enqueue_age_seconds']);
        $this->assertNull($queue['ready_wait_seconds']);
        $this->assertSame('UNKNOWN', $queue['worker_status']);
        $this->assertSame('WARNING', $queue['status']);
    }

    public function test_separate_connections_observe_starts_and_workers_without_cross_queue_leakage(): void
    {
        config(['queue.default' => 'database',
            'queue.connections.finance' => config('queue.connections.database'),
            'reliability.monitored_connections' => ['finance' => ['critical', 'notifications']]]);
        DB::table('jobs')->insert(['queue' => 'critical', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(10)->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp()]);
        $job = \Mockery::mock(DatabaseJob::class);
        $job->shouldReceive('getQueue')->andReturn('critical');
        $job->shouldReceive('payload')->once()->andReturn(['createdAt' => microtime(true) - 5]);
        app(Operations::class)->jobStarted(new JobProcessing('finance', $job));
        Event::dispatch(new Looping('finance', 'critical,notifications'));
        Event::dispatch(new Looping('finance', 'critical,notifications'));
        $this->assertSame(2, DB::table('operational_observations')->where('component', 'queue_worker')->count());
        $report = app(Operations::class)->report()['components']['queue'];
        $this->assertSame('CRITICAL', $report['status']);
        $critical = $report['connections']['finance']['queues']['critical'];
        $this->assertSame('HEALTHY', $critical['worker_status']);
        $this->assertNotNull($critical['last_started_at']);
        $this->assertSame('UNKNOWN', $report['queues']['default']['worker_status']);
        $this->travel(6)->minutes();
        $stale = app(Operations::class)->report()['components']['queue']['connections']['finance']['queues']['critical'];
        $this->assertSame('WARNING', $stale['worker_status']);
    }

    public function test_unavailable_connection_does_not_hide_other_queue_reports(): void
    {
        config(['queue.default' => 'database',
            'queue.connections.broken' => ['driver' => 'database', 'connection' => 'missing', 'table' => 'jobs'],
            'reliability.monitored_connections' => ['broken' => ['financial']]]);
        $queue = app(Operations::class)->report()['components']['queue'];
        $this->assertSame('CRITICAL', $queue['status']);
        $this->assertSame('observation_failed', $queue['connections']['broken']['queues']['financial']['reason']);
        $this->assertArrayHasKey('default', $queue['connections']['database']['queues']);
    }

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
        $this->getJson('/api/v1/admin/reconciliation/'.$id)->assertOk()->assertJsonPath('data.scope_version', 3)->assertDontSee('run_key');
        $this->postJson('/api/v1/admin/reconciliation/'.$id.'/resume', ['reason' => 'Investigated storage failure'])->assertConflict();
        $this->postJson('/api/v1/admin/reconciliation', ['request_key' => (string) Str::uuid()])->assertStatus(429);
        $this->travel(61)->seconds();
        FinancialReconciliationRun::sole()->forceFill(['status' => 'failed'])->save();
        $this->postJson('/api/v1/admin/reconciliation/'.$id.'/resume', ['reason' => 'Investigated storage failure'])->assertStatus(202);
        $this->getJson('/api/v1/admin/reconciliation/missing')->assertNotFound();
        $this->withSession([StepUpCodes::SESSION_KEY => 0]);
        $this->postJson('/api/v1/admin/reconciliation', ['request_key' => (string) Str::uuid()])->assertStatus(423);
    }

    public function test_a_stalled_affiliate_queue_is_reported_even_when_default_is_empty(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'affiliate', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(10)->getTimestamp(), 'created_at' => now()->subMinutes(10)->getTimestamp()]);

        $queue = app(Operations::class)->report()['components']['queue'];
        $this->assertSame('CRITICAL', $queue['status']);
        $this->assertSame([0, null], [$queue['size'], $queue['ready_wait_seconds']]); // default itself is idle
        $this->assertSame(['CRITICAL', 1], [$queue['queues']['affiliate']['status'], $queue['queues']['affiliate']['size']]);
    }

    public function test_operations_detects_ready_queue_stall_and_absence_never_means_healthy(): void
    {
        config(['queue.default' => 'database']);
        $this->assertSame('UNKNOWN', app(Operations::class)->report()['components']['backups']['status']);
        $backup = app(Operations::class)->report()['components']['backups'];
        $this->assertSame(['status' => 'UNAPPROVED', 'financial_rpo_minutes' => 15, 'service_rto_minutes' => 240], $backup['recovery_targets']);
        $this->assertFalse($backup['offsite_verified']);
        $this->assertFalse($backup['key_recovery_verified']);
        $this->assertFalse($backup['infrastructure_drill_verified']);
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
