<?php

namespace App\Console\Commands;

use App\Services\Wallet\WalletService;
use Illuminate\Console\Command;

/**
 * Proves every cached wallet balance against its ledger and lots. A
 * mismatch is flagged and reported as an application error (alert hook).
 */
class WalletReconcile extends Command
{
    protected $signature = 'shopbonus:wallet-reconcile';

    protected $description = 'Check cached wallet balances against the ledger';

    public function handle(WalletService $wallets): int
    {
        $bad = $wallets->reconcile();
        if ($bad !== []) {
            $this->error(count($bad).' wallet(s) do not reconcile; see wallet_reconciliation_flags.');

            return self::FAILURE;
        }
        $this->info('All wallets reconcile.');

        return self::SUCCESS;
    }
}
