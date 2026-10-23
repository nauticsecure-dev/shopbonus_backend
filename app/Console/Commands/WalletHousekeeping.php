<?php

namespace App\Console\Commands;

use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;

/**
 * Expires credit lots past their validity and releases reservations past
 * their hold time.
 */
class WalletHousekeeping extends Command
{
    protected $signature = 'shopbonus:wallet-expire';

    protected $description = 'Expire wallet credit past its validity and release timed-out reservations';

    public function handle(WalletService $wallets): int
    {
        [$lots, $reservations] = $wallets->expire();
        $this->info("Expired {$lots} credit lot(s); released {$reservations} reservation(s).");

        return self::SUCCESS;
    }
}
