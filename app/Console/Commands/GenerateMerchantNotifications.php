<?php

namespace App\Console\Commands;

use App\Services\Analytics\MerchantNotifications;
use Illuminate\Console\Command;

/**
 * Evaluates merchant notification conditions (low inventory, new claims,
 * offers ending soon, connector errors, low funding). Deduplicated.
 */
class GenerateMerchantNotifications extends Command
{
    protected $signature = 'shopbonus:merchant-notifications';

    protected $description = 'Create deduplicated merchant notifications from the current state';

    public function handle(MerchantNotifications $notifications): int
    {
        $this->info('Created '.$notifications->generate().' notification(s).');

        return self::SUCCESS;
    }
}
