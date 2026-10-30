<?php

namespace App\Services\Reliability;

use App\Models\FinancialReconciliationCheck;
use App\Models\FinancialReconciliationRun;
use App\Models\Wallet;
use App\Services\Admin\IncidentRecorder;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** Durable, versioned local source proof; each observation has its own checked time. */
class FinancialReconciliation
{
    public function __construct(private WalletService $wallets) {}

    public function start(string $key): FinancialReconciliationRun
    {
        if (! preg_match('/\A[a-zA-Z0-9:_-]{1,100}\z/D', $key)) {
            throw new InvalidArgumentException('Run keys must be 1–100 ASCII letters, digits, colons, underscores or hyphens.');
        }

        $existing = FinancialReconciliationRun::where('run_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $sources = [];
        foreach (FinancialSources::MODELS as $type => $model) {
            $sources[$type] = ['max' => (int) $model::max('id'), 'cursor' => 0, 'checked' => 0, 'done' => false];
        }

        // scope_version 3 adds first-class direct_merchant conversion checks. Older runs keep their original source_state.
        return FinancialReconciliationRun::firstOrCreate(['run_key' => $key], ['wallet_watermark' => (int) Wallet::max('id'),
            'scope_version' => 3, 'source_state' => $sources]);
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
                        if ($current->scope_version >= 2 && $this->source($current)) {
                            return true;
                        }
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

        $fresh = $run->fresh();
        if ($fresh->discrepancies > 0) {
            app(IncidentRecorder::class)->monitoring('financial_discrepancy', 'financial-reconciliation', 'SYSTEM', ['run' => $fresh->public_id, 'discrepancies' => $fresh->discrepancies]);
        }

        return $fresh;
    }

    /** Runs under the run lock, before any non-locking financial read in this transaction. */
    private function source(FinancialReconciliationRun $run): bool
    {
        $state = $run->source_state ?? [];
        foreach (FinancialSources::MODELS as $type => $model) {
            // Older scope runs omit newer source types; never invent watermarks mid-flight.
            if (! isset($state[$type])) {
                continue;
            }
            $part = $state[$type];
            if ($part['done']) {
                continue;
            }
            $source = $model::where('id', '>', $part['cursor'])->where('id', '<=', $part['max'])->orderBy('id')->lockForUpdate()->first();
            if ($source === null) {
                $state[$type]['done'] = true;

                continue;
            }
            $result = app(FinancialSources::class)->inspect($source);
            FinancialReconciliationCheck::create(['run_id' => $run->id, 'source_type' => $type,
                'source_ref' => $source->getAttribute('public_id'), 'status' => $result['status'], 'evidence' => $result['evidence']]);
            $state[$type]['cursor'] = (int) $source->getKey();
            $state[$type]['checked']++;
            $run->forceFill(['status' => 'running', 'source_state' => $state,
                'unknown_checks' => $run->unknown_checks + ($result['status'] === 'unknown' ? 1 : 0),
                'discrepancies' => $run->discrepancies + ($result['status'] === 'discrepancy' ? 1 : 0)])->save();

            return true;
        }
        $run->forceFill(['source_state' => $state])->save();

        return false;
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
    public function summary(FinancialReconciliationRun $run, bool $refresh = true): array
    {
        if ($refresh) {
            $run->refresh();
        }

        $coverage = ['wallet_projection' => $run->status === 'completed' && $run->checked > 0 ? 'OBSERVED' : 'INCOMPLETE'];
        foreach (FinancialSources::MODELS as $type => $model) {
            $part = $run->source_state[$type] ?? null;
            $coverage[$type] = $run->scope_version >= 2 && $part !== null && $part['done'] ? ($part['checked'] > 0 ? 'OBSERVED' : 'EMPTY') : 'UNKNOWN';
        }

        return ['run' => $run->public_id, 'scope_version' => $run->scope_version, 'execution' => $run->status,
            'status' => $run->discrepancies > 0 ? 'CRITICAL' : 'UNKNOWN',
            'requested_at' => $run->created_at->toIso8601String(), 'finished_at' => $run->completed_at?->toIso8601String(),
            'elapsed_including_scheduling_ms' => max(0, (int) $run->created_at->diffInMilliseconds($run->completed_at ?? now())),
            'checked_wallets' => $run->checked, 'discrepancies' => $run->discrepancies, 'unknown_checks' => $run->unknown_checks,
            'coverage' => $coverage,
            'scope' => 'Individually observed local financial invariants; not independent provider settlement, recognized revenue or a global point-in-time proof.'];
    }
}
