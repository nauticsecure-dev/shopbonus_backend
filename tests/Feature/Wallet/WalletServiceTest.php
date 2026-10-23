<?php

namespace Tests\Feature\Wallet;

use App\Enums\LedgerEntryType;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReconciliationFlag;
use App\Services\Wallet\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * B09: ledger rules (Trello #19 "Balance integrity", #23 "Instant affiliate
 * credit": 12-month validity, oldest-expiring first, no reversal of spent).
 */
class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallets = app(WalletService::class);
        $this->wallet = $this->wallets->consumerWallet(User::factory()->create());
    }

    private function fresh(): Wallet
    {
        return $this->wallet->fresh();
    }

    private function assertReconciles(): void
    {
        $this->assertSame([], $this->wallets->reconcile());
    }

    public function test_credit_is_idempotent_and_valid_for_12_months_by_default(): void
    {
        $first = $this->wallets->credit($this->wallet, 475, 'affiliate:conv-1', meta: ['source_type' => 'affiliate', 'source_ref' => 'conv-1']);
        $retry = $this->wallets->credit($this->wallet, 475, 'affiliate:conv-1');

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(475, $this->fresh()->available);
        $this->assertSame(475, $this->fresh()->total_earned);
        $this->assertSame(1, WalletLedgerEntry::count());
        $this->assertEqualsWithDelta(now()->addMonths(12)->getTimestamp(), $first->expires_at->getTimestamp(), 5);
        $this->assertReconciles();
    }

    public function test_debit_never_goes_below_zero_and_is_idempotent(): void
    {
        $this->wallets->credit($this->wallet, 2000, 'c1');

        $debit = $this->wallets->debit($this->wallet, 2000, 'checkout-1');
        $this->assertSame($debit->id, $this->wallets->debit($this->wallet, 2000, 'checkout-1')->id);
        $this->assertSame([0, 0, 2000], [$this->fresh()->available, $this->fresh()->reserved, $this->fresh()->total_spent]);

        try {
            $this->wallets->debit($this->wallet, 1, 'checkout-2');
            $this->fail('Overspend accepted');
        } catch (ApiException $e) {
            $this->assertSame('insufficient_funds', $e->errorCode);
        }
        $this->assertReconciles();
    }

    public function test_spending_takes_the_oldest_expiring_credit_first(): void
    {
        $late = $this->wallets->credit($this->wallet, 1000, 'late', now()->addMonths(12)->toImmutable());
        $soon = $this->wallets->credit($this->wallet, 1000, 'soon', now()->addMonth()->toImmutable());
        $never = $this->wallets->credit($this->wallet, 1000, 'never', neverExpires: true);

        $this->wallets->debit($this->wallet, 1500, 'buy');

        $remaining = DB::table('wallet_credit_lots')->pluck('remaining', 'entry_id');
        $this->assertSame([$soon->id => 0, $late->id => 500, $never->id => 1000], [$soon->id => (int) $remaining[$soon->id], $late->id => (int) $remaining[$late->id], $never->id => (int) $remaining[$never->id]]);

        // The soonest-expiring lot was used up, so nothing expires next month.
        $this->travel(2)->months();
        $this->assertSame([0, 0], $this->wallets->expire());
        $this->assertSame(1500, $this->fresh()->available);
        $this->assertReconciles();
    }

    public function test_reservation_release_restores_everything_and_capture_debits_once(): void
    {
        $this->wallets->credit($this->wallet, 1000, 'c1');

        $failed = $this->wallets->reserve($this->wallet, 1000, 'checkout-failed');
        $this->assertSame([0, 1000], [$this->fresh()->available, $this->fresh()->reserved]);
        $this->wallets->release($failed, 'payment_failed');
        $this->wallets->release($failed, 'payment_failed'); // idempotent
        $this->assertSame([1000, 0], [$this->fresh()->available, $this->fresh()->reserved]);

        $paid = $this->wallets->reserve($this->wallet, 600, 'checkout-paid');
        $this->assertSame($paid->id, $this->wallets->reserve($this->wallet, 600, 'checkout-paid')->id);
        $capture = $this->wallets->capture($paid);
        $this->assertSame($capture->id, $this->wallets->capture($paid)->id);
        $this->assertSame([400, 0, 600], [$this->fresh()->available, $this->fresh()->reserved, $this->fresh()->total_spent]);

        $this->expectException(ApiException::class);
        $this->wallets->release($paid);
    }

    public function test_timed_out_reservations_are_released_and_credit_expires(): void
    {
        $this->wallets->credit($this->wallet, 1000, 'c1', now()->addDays(10)->toImmutable());
        $this->wallets->reserve($this->wallet, 400, 'abandoned', ttlMinutes: 30);

        $this->travel(31)->minutes();
        $this->assertSame([0, 1], $this->wallets->expire());
        $this->assertSame([1000, 0], [$this->fresh()->available, $this->fresh()->reserved]);

        $this->travel(11)->days();
        $this->assertSame([1, 0], $this->wallets->expire());
        $this->assertSame([0, 1000], [$this->fresh()->available, $this->fresh()->total_expired]);
        $this->assertSame([0, 0], $this->wallets->expire()); // nothing twice
        $this->assertReconciles();
    }

    public function test_pending_credit_is_not_spendable_and_confirms_exactly_once(): void
    {
        $pending = $this->wallets->addPending($this->wallet, 475, 'affiliate:conv-9', ['description' => 'Fietsen Utrecht purchase']);
        $this->assertSame($pending->id, $this->wallets->addPending($this->wallet, 475, 'affiliate:conv-9')->id);
        $this->assertSame([0, 475], [$this->fresh()->available, $this->fresh()->pending]);

        try {
            $this->wallets->debit($this->wallet, 1, 'x');
            $this->fail('Pending credit was spendable');
        } catch (ApiException) {
        }

        $entry = $this->wallets->confirmPending($pending);
        $this->assertSame($entry->id, $this->wallets->confirmPending($pending)->id);
        $this->assertSame([475, 0], [$this->fresh()->available, $this->fresh()->pending]);

        $other = $this->wallets->addPending($this->wallet, 300, 'affiliate:conv-10');
        $this->wallets->cancelPending($other);
        $this->assertSame([475, 0], [$this->fresh()->available, $this->fresh()->pending]);
        $this->expectException(ApiException::class);
        $this->wallets->confirmPending($other);
    }

    public function test_reversal_takes_only_unspent_credit_and_flags_the_rest(): void
    {
        $credit = $this->wallets->credit($this->wallet, 1000, 'affiliate:conv-1');
        $this->wallets->debit($this->wallet, 700, 'voucher-purchase');

        $result = $this->wallets->reverse($credit, 'reverse:conv-1', 'network_reversed');
        $this->assertSame(300, -$result['entry']->amount);
        $this->assertSame(700, $result['unrecovered']);
        $this->assertSame(0, $this->fresh()->available); // never negative
        $this->assertSame(1, WalletReconciliationFlag::where('reason', 'reversal_exceeds_unspent')->where('amount', 700)->count());

        // Retry: nothing more.
        $this->assertSame($result['entry']->id, $this->wallets->reverse($credit, 'reverse:conv-1', 'network_reversed')['entry']->id);
        $this->assertSame(1, WalletReconciliationFlag::count());
        $this->assertReconciles();
    }

    public function test_adjustments_need_a_reason_and_cannot_overdraw(): void
    {
        $this->wallets->credit($this->wallet, 500, 'c1');
        $actor = User::factory()->create();

        $this->wallets->adjust($this->wallet, 250, 'goodwill-1', 'Goodwill for a broken code', $actor->id);
        $this->wallets->adjust($this->wallet, -100, 'correction-1', 'Duplicate goodwill', $actor->id);
        $this->assertSame([650, 500], [$this->fresh()->available, $this->fresh()->total_earned]);

        foreach ([[-1000, 'Too much'], [100, ' ']] as [$amount, $reason]) {
            try {
                $this->wallets->adjust($this->wallet, $amount, 'bad-'.$amount, $reason, $actor->id);
                $this->fail('Bad adjustment accepted');
            } catch (ApiException) {
            }
        }
        $this->assertSame(650, $this->fresh()->available);
        $this->assertReconciles();
    }

    public function test_ledger_entries_are_append_only_in_the_model_and_the_database(): void
    {
        $entry = $this->wallets->credit($this->wallet, 100, 'c1');

        try {
            $entry->forceFill(['amount' => 1_000_000])->save();
            $this->fail('Model update allowed');
        } catch (LogicException) {
        }

        foreach (['update' => fn () => DB::table('wallet_ledger_entries')->update(['amount' => 1_000_000]), 'delete' => fn () => DB::table('wallet_ledger_entries')->delete()] as $op => $sql) {
            try {
                $sql();
                $this->fail("Raw {$op} allowed");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame(100, (int) DB::table('wallet_ledger_entries')->value('amount'));
    }

    public function test_reconcile_detects_a_tampered_cached_balance(): void
    {
        $this->wallets->credit($this->wallet, 100, 'c1');
        DB::table('wallets')->where('id', $this->wallet->id)->update(['available' => 5000]);

        $this->assertSame([$this->wallet->id], $this->wallets->reconcile());
        $this->assertSame(1, WalletReconciliationFlag::where('reason', 'balance_mismatch')->count());
        $this->artisan('shopbonus:wallet-reconcile')->assertFailed();
    }

    public function test_units_are_separate_wallets_never_converted(): void
    {
        $user = User::factory()->create();
        $eur = $this->wallets->consumerWallet($user, WalletUnit::EurCredit);
        $points = $this->wallets->consumerWallet($user, WalletUnit::Points);
        $this->wallets->credit($points, 500, 'p1');

        $this->assertNotSame($eur->id, $points->id);
        $this->assertSame(0, $eur->fresh()->available);
        $this->expectException(ApiException::class);
        $this->wallets->debit($eur, 1, 'x');
    }

    public function test_entry_types_cover_the_card19_event_list(): void
    {
        $this->assertSame(['credit', 'debit', 'reserve', 'release', 'expire', 'adjustment', 'reversal'], array_map(fn ($c) => $c->value, LedgerEntryType::cases()));
    }
}
