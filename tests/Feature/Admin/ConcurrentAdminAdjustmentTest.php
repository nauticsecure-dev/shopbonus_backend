<?php

namespace Tests\Feature\Admin;

use App\Models\AdminApprovalRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\WalletAdjustments;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

/**
 * Trello #19 E2E 8 / #23 test 7: concurrent spends against one wallet, in
 * real processes on MySQL. Exactly the affordable number succeed, the
 * balance is exact and never negative, and the ledger reconciles.
 */
class ConcurrentAdminAdjustmentTest extends TestCase
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

    public function test_concurrent_review_posts_exactly_one_adjustment(): void
    {
        $this->skipUnlessRealDatabase();
        $admins = User::factory()->count(3)->create();
        foreach ($admins as $admin) {
            $admin->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        }
        $consumer = User::factory()->create();
        $wallet = app(WalletService::class)->consumerWallet($consumer);
        $source = app(WalletService::class)->credit($wallet, 500, 'original');
        $request = app(WalletAdjustments::class)->request($admins[0], $wallet, $consumer, $source, 100,
            'Missing credit verified', 'Identity and source checked', 'race-request');
        $results = $this->race(2, fn (int $w) => 'ok:'.app(WalletAdjustments::class)->review(
            AdminApprovalRequest::findOrFail($request->id), User::findOrFail($admins[$w + 1]->id), 'approved', 'Independently checked')->public_id);
        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok:'))), implode(', ', $results));
        $this->assertContains('err:approval_already_reviewed', $results);
        $this->assertSame(600, $wallet->fresh()->available);
        $this->assertSame(2, $wallet->entries()->count());
        $this->assertSame([], app(WalletService::class)->reconcile());
    }
}
