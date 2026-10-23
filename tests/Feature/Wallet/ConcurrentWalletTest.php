<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

/**
 * Trello #19 E2E 8 / #23 test 7: concurrent spends against one wallet, in
 * real processes on MySQL. Exactly the affordable number succeed, the
 * balance is exact and never negative, and the ledger reconciles.
 */
class ConcurrentWalletTest extends TestCase
{
    use DatabaseTruncation, RacesProcesses {
        DatabaseTruncation::truncateDatabaseTables as truncateCommittedTables;
    }

    /**
     * On SQLite these tests are skipped; truncating would mark migrations as
     * done and leave later in-memory RefreshDatabase tests without tables.
     */
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

    public function test_eight_simultaneous_debits_of_5_on_20_exactly_four_succeed(): void
    {
        $this->skipUnlessRealDatabase();
        $wallet = app(WalletService::class)->consumerWallet(User::factory()->create());
        app(WalletService::class)->credit($wallet, 2000, 'seed');

        $results = $this->race(8, fn (int $w) => 'ok:'.app(WalletService::class)->debit(Wallet::findOrFail($wallet->id), 500, "buy-{$w}")->id);

        $this->assertSame(4, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok:'))), implode(', ', $results));
        $this->assertSame(4, count(array_filter($results, fn ($r) => $r === 'err:insufficient_funds')), implode(', ', $results));
        $fresh = $wallet->fresh();
        $this->assertSame([0, 0, 2000], [$fresh->available, $fresh->reserved, $fresh->total_spent]);
        $this->assertSame([], app(WalletService::class)->reconcile());
    }

    public function test_two_simultaneous_requests_with_the_same_key_debit_once(): void
    {
        $this->skipUnlessRealDatabase();
        $wallet = app(WalletService::class)->consumerWallet(User::factory()->create());
        app(WalletService::class)->credit($wallet, 2000, 'seed');

        $results = $this->race(2, fn () => 'ok:'.app(WalletService::class)->debit(Wallet::findOrFail($wallet->id), 2000, 'same-checkout')->id);

        $this->assertSame(1, count(array_unique($results)), implode(', ', $results));
        $this->assertStringStartsWith('ok:', $results[0]);
        $this->assertSame([0, 2000], [$wallet->fresh()->available, $wallet->fresh()->total_spent]);
    }
}
