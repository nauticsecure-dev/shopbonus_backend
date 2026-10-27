<?php

namespace Tests\Feature\Reliability;

use App\Models\User;
use App\Models\WalletReconciliationFlag;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_scans_preserve_one_open_flag_and_never_repair_financial_history(): void
    {
        $service = app(WalletService::class);
        $wallet = $service->consumerWallet(User::factory()->create());
        $service->credit($wallet, 1000, 'synthetic-source');
        DB::table('wallets')->where('id', $wallet->id)->update(['available' => 1400]);
        $first = $service->reconcileWallet($wallet); // Deliberately pass the stale model.
        $this->assertSame([1000, 1400], $first['mismatches']['available']);
        $this->assertSame([$wallet->id], $service->reconcile());
        $this->assertDatabaseCount('wallet_reconciliation_flags', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertSame(1400, $wallet->fresh()->available);
        DB::table('wallets')->where('id', $wallet->id)->update(['available' => 1500]);
        $next = $service->reconcileWallet($wallet);
        $this->assertSame([1000, 1500], $next['mismatches']['available']);
        $this->assertSame($first['flag']->public_id, $next['flag']->public_id);
        $this->assertSame([1000, 1400], WalletReconciliationFlag::sole()->details['available']);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_reconciled_wallet_has_no_finding(): void
    {
        $service = app(WalletService::class);
        $wallet = $service->consumerWallet(User::factory()->create());
        $service->credit($wallet, 1000, 'synthetic-source');
        $this->assertSame([], $service->reconcile());
        $this->assertSame(['mismatches' => [], 'flag' => null], $service->reconcileWallet($wallet));
        $this->assertDatabaseCount('wallet_reconciliation_flags', 0);
    }
}
