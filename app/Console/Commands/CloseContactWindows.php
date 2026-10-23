<?php

namespace App\Console\Commands;

use App\Services\Answer24\ContactPlanner;
use Illuminate\Console\Command;

class CloseContactWindows extends Command
{
    protected $signature = 'shopbonus:close-contact-windows';

    protected $description = 'Close ended one-hour grouping windows and queue eligible Answer24 contact requests';

    public function handle(ContactPlanner $planner): int
    {
        $this->info('Queued '.$planner->closeWindows().' contact opportunity(ies).');

        return self::SUCCESS;
    }
}
