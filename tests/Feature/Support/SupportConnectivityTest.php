<?php

namespace Tests\Feature\Support;

use App\Events\SupportBrowserProbe;
use App\Jobs\ProbeSupportWorker;
use App\Models\User;
use App\Services\Support\ReverbRoundTrip;
use App\Services\Support\SupportConnectivity;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class SupportConnectivityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);

        return $u;
    }

    public function test_worker_probe_is_proven_only_when_a_worker_completes_the_synthetic_job(): void
    {
        Cache::flush();
        config(['broadcasting.default' => 'null', 'queue.default' => 'database']);
        Queue::fake();
        $probe = app(SupportConnectivity::class);
        $this->assertSame('not_measured', $probe->evidence()['worker_probe']);

        $this->assertSame('pending', $probe->run()['worker_probe']);
        $job = null;
        Queue::assertPushed(ProbeSupportWorker::class, function (ProbeSupportWorker $j) use (&$job) {
            $job = $j;

            return true;
        });
        $this->travel(91)->seconds();
        $this->assertSame('not_processed', $probe->evidence()['worker_probe']); // nobody took it

        $job->handle($probe); // Manual invocation has no queued-job context.
        $this->assertSame('not_processed', $probe->evidence()['worker_probe']);
        $context = Mockery::mock(Job::class);
        $context->shouldReceive('getConnectionName')->andReturn('database');
        $context->shouldReceive('getQueue')->andReturn('default');
        $job->setJob($context);
        $job->handle($probe); // Unit fixture; the separate real-worker harness covers actual execution.
        $evidence = $probe->evidence();
        $this->assertSame('processed', $evidence['worker_probe']);
        $this->assertIsInt($evidence['worker_probe_latency_ms']);

        config(['queue.default' => 'sync']);
        $this->assertSame('sync_driver_no_worker', $probe->evidence()['worker_probe']);
    }

    public function test_browser_probe_requires_an_event_only_challenge_and_same_current_admin(): void
    {
        Cache::flush();
        config(['broadcasting.default' => 'reverb']);
        Event::fake([SupportBrowserProbe::class]);
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/ai/connectivity/browser-probe')->assertCreated()->assertJsonMissingPath('data.nonce');
        $id = $response->json('data.probe_id');
        $payload = null;
        Event::assertDispatched(SupportBrowserProbe::class, function (SupportBrowserProbe $e) use (&$payload, $id) {
            $payload = $e->broadcastWith();

            return $payload['probe_id'] === $id;
        });
        $url = '/api/v1/admin/ai/connectivity/browser-probe/'.$id.'/ack';
        $this->postJson($url)->assertUnprocessable();
        $this->postJson($url, ['nonce' => $id])->assertNotFound(); // Public start response alone cannot qualify.
        Sanctum::actingAs($this->admin());
        $this->postJson($url, ['nonce' => $payload['nonce']])->assertNotFound();
        Sanctum::actingAs($admin);
        $this->postJson($url, ['nonce' => $payload['nonce']])->assertOk()->assertJsonPath('data.browser_probe', 'acknowledged');
        $this->postJson($url, ['nonce' => $payload['nonce']])->assertNotFound();
        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/ai/operations')->assertOk()->assertJsonPath('data.connectivity.browser_probe', 'not_measured');
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/ai/operations')->assertOk()->assertJsonPath('data.connectivity.browser_probe', 'acknowledged');
        config(['broadcasting.connections.reverb.key' => 'changed']);
        $this->assertSame('not_measured', app(SupportConnectivity::class)->evidence($admin)['browser_probe']);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/admin/ai/connectivity/browser-probe')->assertForbidden();
    }

    public function test_expired_superseded_and_reconfigured_browser_receipts_are_rejected(): void
    {
        Cache::flush();
        config(['broadcasting.default' => 'reverb']);
        Event::fake([SupportBrowserProbe::class]);
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        // Event::fake retains the event; capture each payload directly from its recorded collection.
        $first = $this->postJson('/api/v1/admin/ai/connectivity/browser-probe')->assertCreated()->json('data.probe_id');
        $payload = Event::dispatched(SupportBrowserProbe::class)->last()[0]->broadcastWith();
        $second = $this->postJson('/api/v1/admin/ai/connectivity/browser-probe')->assertCreated()->json('data.probe_id');
        $secondPayload = Event::dispatched(SupportBrowserProbe::class)->last()[0]->broadcastWith();
        $this->postJson('/api/v1/admin/ai/connectivity/browser-probe/'.$first.'/ack', ['nonce' => $payload['nonce']])->assertNotFound();
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/admin/ai/connectivity/browser-probe/'.$second.'/ack', ['nonce' => $secondPayload['nonce']])->assertNotFound();
        $this->assertSame('expired', app(SupportConnectivity::class)->evidence($admin)['browser_probe']);
        $current = $this->postJson('/api/v1/admin/ai/connectivity/browser-probe')->assertCreated()->json('data.probe_id');
        $currentPayload = Event::dispatched(SupportBrowserProbe::class)->last()[0]->broadcastWith();
        config(['broadcasting.connections.reverb.key' => 'rotated']);
        $this->postJson('/api/v1/admin/ai/connectivity/browser-probe/'.$current.'/ack', ['nonce' => $currentPayload['nonce']])->assertNotFound();
    }

    public function test_worker_evidence_cannot_reuse_older_or_wrong_configuration_completions(): void
    {
        Cache::flush();
        config(['broadcasting.default' => 'null', 'queue.default' => 'database']);
        Queue::fake();
        $p = app(SupportConnectivity::class);
        $p->run();
        $first = Queue::pushed(ProbeSupportWorker::class)->last();
        $p->workerProcessed($first->nonce, $first->dispatchedAt, $first->configuration, 'database', 'default');
        $this->assertSame('processed', $p->evidence()['worker_probe']);
        $this->travel(1)->seconds();
        $p->run();
        $second = Queue::pushed(ProbeSupportWorker::class)->last();
        $this->assertSame('pending', $p->evidence()['worker_probe']);
        $p->workerProcessed($first->nonce, $first->dispatchedAt, $first->configuration, 'database', 'default');
        $p->workerProcessed($second->nonce, $second->dispatchedAt, $second->configuration, 'database', 'other');
        $p->workerProcessed($second->nonce, $second->dispatchedAt - 1, $second->configuration, 'database', 'default');
        $this->assertSame('pending', $p->evidence()['worker_probe']);
        $p->workerProcessed($second->nonce, $second->dispatchedAt, $second->configuration, 'database', 'default');
        $this->assertSame('processed', $p->evidence()['worker_probe']);
        config(['queue.connections.database.queue' => 'changed']);
        $this->assertSame('not_measured', $p->evidence()['worker_probe']);
        $p->workerProcessed($second->nonce, $second->dispatchedAt, $second->configuration, 'database', 'default');
        $this->assertSame('not_measured', $p->evidence()['worker_probe']);
        config(['queue.connections.database.queue' => 'default']);
        $this->assertSame('processed', $p->evidence()['worker_probe']);
        $name = config('queue.connections.database.connection') ?? config('database.default');
        $original = config('database.connections.'.$name.'.database');
        config(['database.connections.'.$name.'.database' => 'synthetic-retarget']);
        $this->assertSame('not_measured', $p->evidence()['worker_probe']);
        config(['database.connections.'.$name.'.database' => $original]);
    }

    public function test_only_fresh_received_round_trips_are_delivery_evidence(): void
    {
        Cache::flush();
        config(['broadcasting.default' => 'reverb']);
        $transport = Mockery::mock(ReverbRoundTrip::class);
        $transport->shouldReceive('check')->once();
        $probe = new SupportConnectivity($transport);
        $this->assertSame('not_measured', $probe->evidence()['reverb_live_probe']);
        $this->assertSame('connected', $probe->run()['reverb_live_probe']);
        $this->assertSame('synthetic_round_trip_received', $probe->evidence()['broadcast_evidence']);
        $this->travel(91)->seconds();
        $this->assertSame('stale', $probe->evidence()['reverb_live_probe']);
        $this->assertSame('dispatch_attempt_only', $probe->evidence()['broadcast_evidence']);
        config(['broadcasting.connections.reverb.key' => 'changed']);
        $this->assertSame('not_measured', $probe->evidence()['reverb_live_probe']);
        $this->assertNull($probe->evidence()['reverb_probe_checked_at']);
    }

    public function test_failed_probe_replaces_success_without_leaking_connection_errors(): void
    {
        Cache::flush();
        config(['broadcasting.default' => 'reverb']);
        $transport = Mockery::mock(ReverbRoundTrip::class);
        $transport->shouldReceive('check')->once()->ordered();
        $transport->shouldReceive('check')->once()->ordered()->andThrow(new \RuntimeException('SYNTHETIC_SECRET'));
        $probe = new SupportConnectivity($transport);
        $probe->run();
        $result = $probe->run();
        $this->assertSame('failed', $result['reverb_live_probe']);
        $this->assertSame('dispatch_attempt_only', $result['broadcast_evidence']);
        $this->assertStringNotContainsString('SYNTHETIC_SECRET', json_encode($result));
    }

    public function test_disabled_broadcaster_never_runs_network_probe(): void
    {
        Cache::flush();
        config(['broadcasting.default' => 'null']);
        $transport = Mockery::mock(ReverbRoundTrip::class);
        $transport->shouldNotReceive('check');
        $this->assertSame('not_configured', (new SupportConnectivity($transport))->run()['reverb_live_probe']);
    }

    public function test_command_reports_failed_round_trip_with_nonzero_exit(): void
    {
        Cache::flush();
        config(['broadcasting.default' => 'reverb']);
        $transport = Mockery::mock(ReverbRoundTrip::class);
        $transport->shouldReceive('check')->once()->andThrow(new \RuntimeException('SYNTHETIC_SECRET'));
        $this->instance(ReverbRoundTrip::class, $transport);
        $this->artisan('shopbonus:probe-support-connectivity')->assertExitCode(1);
        $this->assertSame('failed', app(SupportConnectivity::class)->evidence()['reverb_live_probe']);
    }
}
