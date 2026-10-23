<?php

namespace App\Console\Commands;

use App\Services\Vouchers\VoucherInventory;
use Illuminate\Console\Command;

/**
 * Releases reservations whose claim window passed (e.g. the 90-day Answer24
 * claim) and expires issued vouchers past their redemption validity.
 */
class ExpireVouchers extends Command
{
    protected $signature = 'shopbonus:expire-vouchers';

    protected $description = 'Release expired voucher reservations and expire overdue issued vouchers';

    public function handle(VoucherInventory $inventory): int
    {
        [$released, $expired] = $inventory->expire();
        $this->info("Released {$released} unclaimed reservation(s); expired {$expired} voucher(s).");

        return self::SUCCESS;
    }
}
