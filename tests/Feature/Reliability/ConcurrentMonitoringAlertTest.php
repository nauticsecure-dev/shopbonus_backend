<?php

namespace Tests\Feature\Reliability;

use App\Models\SecurityIncident;
use App\Models\User;
use App\Models\WalletReconciliationFlag;
use App\Services\Reliability\ApiMonitoring;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

class ConcurrentMonitoringAlertTest extends TestCase
{
    use DatabaseTruncation, RacesProcesses {
        DatabaseTruncation::truncateDatabaseTables as truncateCommittedTables;
    }

    protected function truncateDatabaseTables(): void
    {
        if (in_array(config('database.default'), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->truncateCommittedTables();
        }
    }

    protected function tearDown(): void
    {
        $this->truncateAfterRace();
        parent::tearDown();
    }

    public function test_threshold_evaluators_share_a_serialized_incident_cooldown(): void
    {
        $this->skipUnlessRealDatabase();
        config(['reliability.enabled' => true, 'reliability.minimum_samples' => 1]);
        DB::table('api_observations')->insert(['route' => 'api/v1/health', 'method' => 'GET', 'status' => 500, 'duration_us' => 1000,
            'query_count' => 1, 'query_us' => 100, 'slow_queries' => 0, 'observed_at' => now()]);
        $results = $this->race(4, fn () => 'breaches:'.app(ApiMonitoring::class)->evaluate());
        $this->assertSame(['breaches:1'], array_values(array_unique($results)), implode(', ', $results));
        $this->assertDatabaseCount('security_incidents', 1);
        $this->assertDatabaseCount('security_incident_occurrences', 1);
        $this->assertSame(1, SecurityIncident::sole()->occurrences);
    }

    public function test_parallel_wallet_checks_retain_one_unresolved_finding(): void
    {
        $this->skipUnlessRealDatabase();
        $service = app(WalletService::class);
        $wallet = $service->consumerWallet(User::factory()->create());
        $service->credit($wallet, 1000, 'synthetic-source');
        DB::table('wallets')->where('id', $wallet->id)->update(['available' => 1400]);
        $results = $this->race(4, fn () => app(WalletService::class)->reconcileWallet($wallet)['flag']->public_id);
        $this->assertSame(1, count(array_unique($results)), implode(', ', $results));
        $this->assertSame(WalletReconciliationFlag::sole()->public_id, $results[0]);
        $this->assertSame(1400, $wallet->fresh()->available);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }
}
