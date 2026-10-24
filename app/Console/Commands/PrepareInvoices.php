<?php

namespace App\Console\Commands;

use App\Services\Funding\MerchantFunding;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Builds each webshop's monthly statement of settled rewards and fees as a
 * draft invoice. Moneybird is not connected yet (OPEN-FINANCIAL-LEGAL), so
 * nothing is sent.
 */
class PrepareInvoices extends Command
{
    protected $signature = 'shopbonus:prepare-invoices {period? : YYYY-MM (default: last month)}';

    protected $description = 'Prepare draft invoices of settled sponsored rewards and fees';

    public function handle(MerchantFunding $funding): int
    {
        $period = (string) ($this->argument('period') ?? CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m'));
        $this->info("Prepared {$funding->prepareInvoices($period)} draft invoice(s) for {$period}.");

        return self::SUCCESS;
    }
}
