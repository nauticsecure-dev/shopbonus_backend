<?php

namespace Tests\Feature\Support;

use App\Events\SupportBrowserProbe;
use App\Jobs\ProbeSupportWorker;
use App\Models\User;
use App\Services\Support\ReverbRoundTrip;
use App\Services\Support\SupportConnectivity;
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

        $job->handle($probe); // what a running worker does
        $evidence = $probe->evidence();
        $this->assertSame('processed', $evidence['worker_probe']);
        $this->assertIsInt($evidence['worker_probe_latency_ms']);

        config(['queue.default' => 'sync']);
        $this->assertSame('sync_driver_no_worker', $probe->evidence()['worker_probe']);
    }

    public function test_browser_probe_needs_the_same_admin_to_echo_a_fresh_single_use_nonce(): void
    {
        Cache::flush();
        Event::fake([SupportBrowserProbe::class]);
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/ai/operations')->assertOk()->assertJsonPath('data.connectivity.browser_probe', 'not_measured');

        $nonce = $this->postJson('/api/v1/admin/ai/connectivity/browser-probe')->assertCreated()
            ->assertJsonPath('data.event', 'support.connectivity.browser_probe')->json('data.nonce');
        Event::assertDispatched(SupportBrowserProbe::class, fn (SupportBrowserProbe $e) => $e->broadcastWith() === ['nonce' => $nonce]
            && $e->broadcastOn()[0]->name === 'private-shopbonus.user.'.$admin->public_id.'.notifications');

        // Another admin cannot acknowledge it; a guess is refused.
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/ai/connectivity/browser-probe/{$nonce}/ack")->assertNotFound();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/ai/connectivity/browser-probe/'.str_repeat('a', 32).'/ack')->assertNotFound();

        $this->postJson("/api/v1/admin/ai/connectivity/browser-probe/{$nonce}/ack")->assertOk()->assertJsonPath('data.browser_probe', 'acknowledged');
        $this->postJson("/api/v1/admin/ai/connectivity/browser-probe/{$nonce}/ack")->assertNotFound(); // single use

        // A nonce older than the window cannot be acknowledged.
        $late = $this->postJson('/api/v1/admin/ai/connectivity/browser-probe')->json('data.nonce');
        $this->travel(61)->seconds();
        $this->postJson("/api/v1/admin/ai/connectivity/browser-probe/{$late}/ack")->assertNotFound();
        $this->travel(15)->minutes();
        $this->assertSame('stale', app(SupportConnectivity::class)->evidence()['browser_probe']);

        // Non-admins cannot start probes.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/admin/ai/connectivity/browser-probe')->assertForbidden();
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
