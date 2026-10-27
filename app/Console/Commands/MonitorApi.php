<?php

namespace App\Console\Commands;

use App\Services\Reliability\ApiMonitoring;
use Illuminate\Console\Command;

class MonitorApi extends Command
{
    protected $signature = 'shopbonus:monitor-api';

    protected $description = 'Evaluate observed API thresholds and prune bounded metadata retention';

    public function handle(ApiMonitoring $monitoring): int
    {
        $breaches = $monitoring->evaluate();
        $pruned = $monitoring->prune();
        $this->info("Observed route threshold breaches: {$breaches}; old observations pruned: {$pruned}.");

        return self::SUCCESS;
    }
}
