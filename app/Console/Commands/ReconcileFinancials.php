<?php

namespace App\Console\Commands;

use App\Models\FinancialReconciliationRun;
use App\Services\Reliability\FinancialReconciliation;
use Illuminate\Console\Command;

class ReconcileFinancials extends Command
{
    protected $signature = 'shopbonus:reconcile-financials {--key= : Stable run identity; defaults to current UTC hour} {--resume : Explicitly resume a failed keyed run after investigation} {--limit=100 : Wallets per invocation, 1–100}';

    protected $description = 'Advance a durable bounded wallet reconciliation run without changing balances';

    public function handle(FinancialReconciliation $reconciliation): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('Batch size must be between 1 and 100.');

            return self::INVALID;
        }
        $key = $this->option('key');
        if ($key !== null && ! preg_match('/\A[a-zA-Z0-9:_-]{1,100}\z/D', $key)) {
            $this->error('Invalid run key.');

            return self::INVALID;
        }
        if ($this->option('resume') && $key === null) {
            $this->error('Resuming requires an explicit run key.');

            return self::INVALID;
        }
        // Drain unfinished work before starting another hourly scan; failed runs remain visible.
        $run = $key === null ? FinancialReconciliationRun::whereIn('status', ['pending', 'running'])->oldest('id')->first() : null;
        $run ??= $reconciliation->start($key ?? 'hour:'.now('UTC')->format('Y-m-d-H'));
        if ($this->option('resume')) {
            $reconciliation->resume($run);
        }
        $run = $reconciliation->advance($run, $limit);
        $this->line(json_encode($reconciliation->summary($run), JSON_THROW_ON_ERROR));

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
