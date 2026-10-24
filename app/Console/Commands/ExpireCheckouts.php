<?php

namespace App\Console\Commands;

use App\Services\Checkout\CheckoutService;
use Illuminate\Console\Command;

/**
 * Settles checkouts past their soft expiry: re-checks Mollie (late payments
 * are finalised), releases wallet and code reservations of the rest.
 */
class ExpireCheckouts extends Command
{
    protected $signature = 'shopbonus:expire-checkouts';

    protected $description = 'Settle expired voucher checkouts (re-check Mollie, release reservations)';

    public function handle(CheckoutService $checkouts): int
    {
        $this->info('Settled '.$checkouts->expire().' checkout(s).');

        return self::SUCCESS;
    }
}
