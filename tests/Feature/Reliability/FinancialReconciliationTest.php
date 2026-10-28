<?php

namespace Tests\Feature\Reliability;

use App\Models\FinancialReconciliationCheck;
use App\Models\FinancialReconciliationRun;
use App\Models\User;
use App\Services\Reliability\FinancialReconciliation;
use App\Services\Wallet\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinancialReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounded_run_replays_preserve_watermark_and_original_evidence(): void
    {
        $wallets = app(WalletService::class);
        $first = $wallets->consumerWallet(User::factory()->create());
        $wallets->credit($first, 1000, 'source-one');
        DB::table('wallets')->where('id', $first->id)->update(['available' => 1400]);
        $runner = app(FinancialReconciliation::class);
        $run = $runner->start('test:one');
        $late = $wallets->consumerWallet(User::factory()->create());
        $this->assertSame($run->id, $runner->start('test:one')->id);
        $this->assertSame($first->id, $run->wallet_watermark);
        $run = $runner->advance($run, 1);
        $this->assertSame(1, $run->checked);
        $this->assertSame(1, $run->discrepancies);
        $this->assertSame('running', $run->status);
        $evidence = FinancialReconciliationCheck::where('source_type', 'wallet')->sole()->evidence;
        $this->assertSame([1000, 1400], $evidence['mismatches']['available']);
        DB::table('wallets')->where('id', $first->id)->update(['available' => 1500]);
        $run = $runner->advance($run);
        $this->assertSame('completed', $run->status);
        $this->assertSame('CRITICAL', $runner->summary($run)['status']);
        $this->assertSame('EMPTY', $runner->summary($run)['coverage']['affiliate']);
        $runner->advance($run);
        $this->assertDatabaseCount('financial_reconciliation_checks', 2);
        $this->assertSame($evidence, FinancialReconciliationCheck::where('source_type', 'wallet')->sole()->evidence);
        $this->assertSame(1500, $first->fresh()->available);
        $this->assertSame(0, $late->fresh()->available);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('wallet_reconciliation_flags', 1);
        $next = $runner->advance($runner->start('test:two'));
        $this->assertSame(2, $next->checked);
        $this->assertDatabaseCount('wallet_reconciliation_flags', 1);
    }

    public function test_empty_or_matched_runs_never_claim_platform_health(): void
    {
        $runner = app(FinancialReconciliation::class);
        $empty = $runner->advance($runner->start('empty'));
        $this->assertSame('completed', $empty->status);
        $this->assertSame('UNKNOWN', $runner->summary($empty)['status']);
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->consumerWallet(User::factory()->create()), 100, 'one');
        $matched = $runner->advance($runner->start('matched'));
        $this->assertSame(1, $matched->checked);
        $this->assertSame(0, $matched->discrepancies);
        $this->assertSame('OBSERVED', $runner->summary($matched)['coverage']['wallet_projection']);
        $this->assertSame('UNKNOWN', $runner->summary($matched)['status']);
        $this->assertSame('matched', FinancialReconciliationCheck::where('source_type', 'wallet')->sole()->status);
    }

    public function test_failed_check_rolls_back_and_explicit_resume_does_not_repeat_committed_checks(): void
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet(User::factory()->create());
        $runner = app(FinancialReconciliation::class);
        $run = $runner->start('failed');
        $broken = \Mockery::mock(WalletService::class);
        $broken->shouldReceive('reconcileWallet')->once()->andThrow(new \RuntimeException('SECRET source data'));
        try {
            (new FinancialReconciliation($broken))->advance($run);
            $this->fail('Expected failed check.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SECRET source data', $e->getMessage());
        }
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->wallet_cursor);
        $this->assertDatabaseCount('financial_reconciliation_checks', 0);
        $this->assertStringNotContainsString('SECRET', $run->fresh()->toJson());
        $runner->advance($run);
        $this->assertDatabaseCount('financial_reconciliation_checks', 0);
        $runner->resume($run);
        $runner->advance($run);
        $this->assertSame($wallet->id, $run->fresh()->wallet_cursor);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('financial_reconciliation_checks', 1);
    }

    public function test_command_rejects_unbounded_batches_and_unsafe_keys(): void
    {
        $this->artisan('shopbonus:reconcile-financials', ['--limit' => 101])->assertExitCode(2);
        $this->artisan('shopbonus:reconcile-financials', ['--key' => 'bad/key'])->assertExitCode(2);
        $this->artisan('shopbonus:reconcile-financials', ['--resume' => true])->assertExitCode(2);
        $this->assertDatabaseCount('financial_reconciliation_runs', 0);
        $this->artisan('shopbonus:reconcile-financials', ['--key' => 'manual:one'])->assertSuccessful();
        $this->assertSame('completed', FinancialReconciliationRun::sole()->status);
    }

    public function test_check_evidence_is_immutable_at_database_level(): void
    {
        app(WalletService::class)->consumerWallet(User::factory()->create());
        $runner = app(FinancialReconciliation::class);
        $runner->advance($runner->start('immutable'));
        $this->expectException(QueryException::class);
        DB::table('financial_reconciliation_checks')->update(['status' => 'rewritten']);
    }
}
