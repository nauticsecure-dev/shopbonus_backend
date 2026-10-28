<?php

namespace App\Services\Reliability;

use App\Models\FinancialReconciliationCheck;
use App\Models\FinancialReconciliationRun;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** Durable wallet projection proof. Other financial source coverage is explicitly unknown. */
class FinancialReconciliation
{
    public function __construct(private WalletService $wallets) {}

    public function start(string $key): FinancialReconciliationRun
    {
        if (! preg_match('/\A[a-zA-Z0-9:_-]{1,100}\z/D', $key)) {
            throw new InvalidArgumentException('Run keys must be 1–100 ASCII letters, digits, colons, underscores or hyphens.');
        }

        return FinancialReconciliationRun::firstOrCreate(['run_key' => $key], ['wallet_watermark' => (int) Wallet::max('id')]);
    }

    /** Process at most $limit wallets; cursor and immutable proof commit together. */
    public function advance(FinancialReconciliationRun $run, int $limit = 100): FinancialReconciliationRun
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Batch size must be between 1 and 100.');
        }
        try {
            for ($i = 0; $i < $limit; $i++) {
                $progress = DB::transaction(function () use ($run) {
                    $current = FinancialReconciliationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                    if (in_array($current->status, ['completed', 'failed'], true)) {
                        return false;
                    }
                    // Locking reads do not open an older InnoDB consistent-read snapshot.
                    // The wallet must be locked before WalletService's first financial aggregate.
                    $wallet = Wallet::where('id', '>', $current->wallet_cursor)
                        ->where('id', '<=', $current->wallet_watermark)->orderBy('id')->lockForUpdate()->first();
                    if ($wallet === null) {
                        $current->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

                        return false;
                    }
                    $check = $this->wallets->reconcileWallet($wallet);
                    FinancialReconciliationCheck::create([
                        'run_id' => $current->id, 'source_type' => 'wallet', 'source_ref' => $wallet->public_id,
                        'status' => $check['mismatches'] === [] ? 'matched' : 'discrepancy',
                        'evidence' => ['version' => 1, 'unit' => $wallet->unit->value, 'kind' => $wallet->kind->value,
                            'projection' => ['available' => $wallet->available, 'reserved' => $wallet->reserved, 'pending' => $wallet->pending],
                            'mismatches' => $check['mismatches'], 'flag' => $check['flag']?->public_id],
                    ]);
                    $current->forceFill(['status' => 'running', 'wallet_cursor' => $wallet->id,
                        'checked' => $current->checked + 1,
                        'discrepancies' => $current->discrepancies + ($check['mismatches'] === [] ? 0 : 1)])->save();

                    return true;
                }, 3);
                if (! $progress) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // Never persist exception messages: database errors may contain source values.
            DB::transaction(function () use ($run, $e) {
                $current = FinancialReconciliationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($current->status !== 'completed') {
                    $current->forceFill(['status' => 'failed', 'failure_class' => substr($e::class, 0, 160)])->save();
                }
            });
            throw $e;
        }

        return $run->fresh();
    }

    /** Explicit operator retry after investigating a failure; committed checks are preserved. */
    public function resume(FinancialReconciliationRun $run): void
    {
        DB::transaction(function () use ($run) {
            $current = FinancialReconciliationRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'failed') {
                $current->forceFill(['status' => 'running'])->save();
            }
        });
    }

    /** @return array<string, mixed> */
    public function summary(FinancialReconciliationRun $run): array
    {
        $run->refresh();

        return ['run' => $run->public_id, 'execution' => $run->status,
            'status' => $run->discrepancies > 0 ? 'CRITICAL' : 'UNKNOWN',
            'checked_wallets' => $run->checked, 'discrepant_wallets' => $run->discrepancies,
            'coverage' => ['wallet_projection' => $run->status === 'completed' && $run->checked > 0 ? 'OBSERVED' : 'INCOMPLETE',
                'affiliate' => 'UNKNOWN', 'checkout' => 'UNKNOWN', 'funding' => 'UNKNOWN', 'sponsorship' => 'UNKNOWN'],
            'scope' => 'Wallet projections observed individually; not a platform-wide point-in-time financial proof.'];
    }
}
