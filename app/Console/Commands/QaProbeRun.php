<?php

namespace App\Console\Commands;

use App\Services\Qa\QaProbe;
use App\Services\Qa\QaRuns;
use Illuminate\Console\Command;

class QaProbeRun extends Command
{
    protected $signature = 'shopbonus:qa-probe {--prune : Delete expired evidence and old probe runs instead}';

    protected $description = 'Read-only Quality Center health probe (no business requests or writes outside the QA tables)';

    public function handle(QaProbe $probe, QaRuns $runs): int
    {
        if ($this->option('prune')) {
            $this->info('Pruned: '.json_encode($runs->prune()));

            return self::SUCCESS;
        }
        if (! config('qa.probe_enabled')) {
            $this->info('QA probe disabled (QA_PROBE_ENABLED=false).');

            return self::SUCCESS;
        }
        $run = $probe->run();
        $this->info('Probe '.$run->public_id.': '.json_encode($run->totals));

        return ($run->totals['fail'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
