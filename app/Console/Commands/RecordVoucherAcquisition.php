<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\AffiliateTransaction;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Console\Command;

/** Privileged reconciliation; an independently checked link, never a wallet heuristic. */
class RecordVoucherAcquisition extends Command
{
    protected $signature = 'shopbonus:voucher-acquisition {voucher : Voucher public ID} {transaction : Approved acquisition transaction public ID} {--actor= : Verified active Super Admin public ID} {--reference= : Independently reviewed acquisition evidence case reference} {--verified-link : Attest that evidence explicitly links this voucher acquisition to this transaction}';

    protected $description = 'Record an explicitly verified voucher acquisition source without inferring wallet attribution';

    public function handle(VoucherInventory $inventory): int
    {
        if (! $this->option('actor') || ! $this->option('reference') || ! $this->option('verified-link')) {
            $this->error('Actor, reference and verified-link attestation are required. Wallet spending is not acquisition evidence.');

            return self::FAILURE;
        }
        $actor = User::where('public_id', $this->option('actor'))->first();
        $voucher = Voucher::where('public_id', $this->argument('voucher'))->first();
        $transaction = AffiliateTransaction::where('public_id', $this->argument('transaction'))->first();
        if ($actor === null || $voucher === null || $transaction === null) {
            $this->error('Actor, voucher or transaction not found.');

            return self::FAILURE;
        }
        try {
            $inventory->recordAcquisition($voucher, $transaction, $actor, (string) $this->option('reference'));
        } catch (ApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Verified acquisition link recorded (same-link retries are idempotent).');

        return self::SUCCESS;
    }
}
