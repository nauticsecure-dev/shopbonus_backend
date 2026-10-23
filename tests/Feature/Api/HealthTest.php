<?php

namespace Tests\Feature\Api;

use App\Services\Health\HealthChecker;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_liveness_returns_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'shopbonus-api');
    }

    public function test_readiness_checks_dependencies_and_hides_detail_by_default(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_shows_detail_only_with_matching_token(): void
    {
        config(['shopbonus.health.readiness_token' => 'probe-token-123']);

        $this->getJson('/api/v1/health/ready', ['X-Health-Token' => 'wrong'])
            ->assertJsonMissingPath('checks');

        $this->getJson('/api/v1/health/ready', ['X-Health-Token' => 'probe-token-123'])
            ->assertOk()
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok');
    }

    public function test_readiness_returns_503_when_a_dependency_fails(): void
    {
        $this->mock(HealthChecker::class)
            ->shouldReceive('run')
            ->andReturn(['database' => HealthChecker::FAIL, 'cache' => HealthChecker::OK]);

        $this->getJson('/api/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'fail');
    }

    public function test_readiness_reports_database_failure(): void
    {
        $connection = config('database.default');
        $missing = config("database.connections.{$connection}.driver") === 'sqlite'
            ? '/nonexistent/dir/db.sqlite'
            : 'shopbonus_missing_db';
        config(["database.connections.{$connection}.database" => $missing]);
        $this->app['db']->purge($connection);

        $results = $this->app->make(HealthChecker::class)->run();

        $this->assertSame(HealthChecker::FAIL, $results['database']);
        $this->assertSame(HealthChecker::OK, $results['cache']);
    }
}
