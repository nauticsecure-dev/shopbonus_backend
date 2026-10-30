<?php

namespace App\Console\Commands;

use App\Services\Offers\OfferService;
use Illuminate\Console\Command;

/**
 * Opens scheduled offers once their start is reached and ends offers past
 * their end date (active, paused, or still scheduled).
 */
class AdvanceOfferLifecycle extends Command
{
    protected $signature = 'shopbonus:advance-offer-lifecycle';

    protected $description = 'Activate due scheduled offers and end offers past their end date';

    public function handle(OfferService $offers): int
    {
        $result = $offers->advanceLifecycle();
        $this->info("Activated {$result['activated']} offer(s); ended {$result['ended']} offer(s).");

        return self::SUCCESS;
    }
}
