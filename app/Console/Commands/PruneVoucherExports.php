<?php

namespace App\Console\Commands;

use App\Services\Vouchers\VoucherExports;
use Illuminate\Console\Command;

/** Trello #17: queued voucher export files are deleted once their download window ends. */
class PruneVoucherExports extends Command
{
    protected $signature = 'shopbonus:prune-voucher-exports';

    protected $description = 'Delete expired merchant voucher export files';

    public function handle(VoucherExports $exports): int
    {
        $this->info('Expired '.$exports->prune().' voucher export(s).');

        return self::SUCCESS;
    }
}
