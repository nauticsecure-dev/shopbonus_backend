<?php

namespace App\Console\Commands;

use App\Services\Reliability\Operations;
use Illuminate\Console\Command;

class ObserveOperations extends Command
{
    protected $signature = 'shopbonus:observe-operations';

    protected $description = 'Observe dependency/queue/financial/recovery status and record operational incidents';

    public function handle(Operations $operations): int
    {
        $operations->observe();
        $this->info('Operational observation recorded.');

        return self::SUCCESS;
    }
}
