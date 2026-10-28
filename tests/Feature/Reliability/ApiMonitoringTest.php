<?php

namespace Tests\Feature\Reliability;

use App\Enums\PlatformRole;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentOccurrence;
use App\Models\User;
use App\Services\Reliability\ApiMonitoring;
use App\Services\Reliability\RequestObservation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_observations_never_store_sql_bindings_or_actual_paths_and_do_not_recurse(): void
    {
        config(['reliability.enabled' => true]);
        $this->getJson('/api/v1/public/webshops/private-secret-slug?token=secret')->assertNotFound();
        $row = DB::table('api_observations')->sole();
        $this->assertSame('api/v1/public/webshops/{slug}', $row->route);
        $this->assertSame(404, (int) $row->status);
        $this->assertGreaterThan(0, $row->query_count);
        $this->assertStringNotContainsString('secret', json_encode($row));
        $this->assertDatabaseCount('api_observations', 1);
        $this->getJson('/api/v1/does-not-exist/private-secret?token=secret')->assertNotFound();
        $this->assertSame('unregistered', DB::table('api_observations')->orderByDesc('id')->first()->route);
        config(['reliability.enabled' => false]);
        $this->getJson('/api/v1/health')->assertOk();
        $this->assertDatabaseCount('api_observations', 2);
    }

    public function test_quantiles_are_exact_and_only_measured_5xx_timeouts_trigger_thresholds(): void
    {
        config(['reliability.enabled' => true]);
        for ($i = 1; $i <= 100; $i++) {
            $this->sample($i * 1000, $i === 100 ? 504 : ($i <= 30 ? 403 : 200));
        }
        $monitor = app(ApiMonitoring::class);
        $r = $monitor->report();
        $route = $r['routes'][0];
        $this->assertSame([100, 50, 95, 99], [$route['requests'], (int) $route['p50_ms'], (int) $route['p95_ms'], (int) $route['p99_ms']]);
        $this->assertEquals(50.5, $route['average_ms']);
        $this->assertSame([30, 1, 1], [$route['client_errors'], $route['server_errors'], $route['observed_timeouts']]);
        $this->assertSame('HEALTHY', $r['state']); // 1%5xx is below the2% threshold; ordinary403s are not alerts.
        config(['reliability.error_percent' => 0.5, 'reliability.p95_ms' => 90]);
        $this->assertSame('CRITICAL', $monitor->report()['state']);
        $this->assertSame(2, $monitor->evaluate());
        $this->assertSame(2, $monitor->evaluate());
        $this->assertDatabaseCount('security_incidents', 2);
        $this->assertDatabaseCount('security_incident_occurrences', 2);
        $this->travel(1)->minutes();
        $monitor->evaluate();
        $this->assertDatabaseCount('security_incident_occurrences', 2);
        $this->assertSame('api_monitoring', SecurityIncident::first()->service);
        $evidence = SecurityIncidentOccurrence::first()->monitoring_evidence;
        $this->assertSame(100, $evidence['requests']);
        $this->assertEquals(95, $evidence['p95_ms']);
    }

    public function test_absent_disabled_and_insufficient_observations_are_unknown_and_retention_is_bounded(): void
    {
        $monitor = app(ApiMonitoring::class);
        $this->assertSame('UNKNOWN', $monitor->report()['state']);
        config(['reliability.enabled' => true]);
        $this->sample(1000);
        $this->assertSame('UNKNOWN', $monitor->report()['state']);
        $this->sample(9000, 500, now()->subHours(25));
        $this->artisan('shopbonus:monitor-api')->assertSuccessful();
        $this->assertDatabaseCount('api_observations', 1);
        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_storage_failure_does_not_change_the_original_response(): void
    {
        config(['reliability.enabled' => true]);
        Schema::drop('api_observations');
        Log::spy();
        $this->getJson('/api/v1/health')->assertOk();
        Log::shouldHaveReceived('warning')->with('API observation could not be stored', \Mockery::type('array'))->once();
    }

    public function test_report_is_admin_only_and_validates_scope_without_leaking_raw_samples(): void
    {
        $url = '/api/v1/admin/monitoring';
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($url)->assertForbidden();
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        Sanctum::actingAs($admin);
        $this->getJson($url.'?minutes=61')->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('data.state', 'UNKNOWN')->assertJsonPath('data.coverage.backups', 'UNKNOWN');
        config(['shopbonus.admin.mfa_required' => true]);
        $this->getJson($url)->assertForbidden();
    }

    public function test_query_counters_reset_between_requests_and_slow_threshold_is_configurable(): void
    {
        config(['reliability.enabled' => true, 'reliability.slow_query_ms' => 10]);
        $observation = app(RequestObservation::class);
        $request = Request::create('/api/v1/health');
        $observation->start($request);
        $observation->query(new QueryExecuted('SELECT private_secret', ['token'], 12.5, DB::connection()));
        $observation->finish($request, response('', 504));
        $this->assertSame(1, (int) DB::table('api_observations')->sole()->slow_queries);
        $observation->start($request);
        $observation->finish($request, response('', 200));
        $this->assertSame(0, (int) DB::table('api_observations')->orderByDesc('id')->first()->query_count);
        $this->assertStringNotContainsString('private_secret', json_encode(DB::table('api_observations')->get()));
    }

    public function test_partial_samples_are_explicitly_unknown_and_do_not_emit_threshold_alerts(): void
    {
        config(['reliability.enabled' => true, 'reliability.sample_limit' => 100]);
        for ($i = 0; $i < 101; $i++) {
            $this->sample(2000000, 500);
        }
        $monitor = app(ApiMonitoring::class);
        $this->assertTrue($monitor->report()['sample_limit_exceeded']);
        $this->assertSame('UNKNOWN', $monitor->report()['state']);
        $this->assertSame(0, $monitor->evaluate());
        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_rendered_server_errors_and_timeouts_are_observed(): void
    {
        config(['reliability.enabled' => true]);
        Route::get('/api/v1/monitor-test-error', fn () => throw new \RuntimeException('Synthetic failure'));
        Route::get('/api/v1/monitor-test-timeout', fn () => response()->json(['test' => true], 504));
        $this->getJson('/api/v1/monitor-test-error')->assertStatus(500);
        $this->getJson('/api/v1/monitor-test-timeout')->assertStatus(504);
        $this->assertSame([500, 504], DB::table('api_observations')->orderBy('id')->pluck('status')->map(fn ($status) => (int) $status)->all());
    }

    private function sample(int $durationUs, int $status = 200, mixed $at = null): void
    {
        DB::table('api_observations')->insert(['route' => 'api/v1/health', 'method' => 'GET', 'status' => $status, 'duration_us' => $durationUs,
            'query_count' => 1, 'query_us' => 100, 'slow_queries' => 0, 'observed_at' => $at ?? now()]);
    }
}
