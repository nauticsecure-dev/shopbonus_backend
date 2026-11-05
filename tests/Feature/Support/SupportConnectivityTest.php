<?php

namespace Tests\Feature\Support;

use App\Services\Support\ReverbRoundTrip;
use App\Services\Support\SupportConnectivity;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SupportConnectivityTest extends TestCase
{
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
