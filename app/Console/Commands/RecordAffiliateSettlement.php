<?php

namespace App\Console\Commands;

use App\Models\AffiliateTransaction;
use App\Models\User;
use App\Services\Affiliate\SettlementRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RecordAffiliateSettlement extends Command
{
    protected $signature = 'shopbonus:affiliate-settlement {transaction : Conversion public ID} {--actor= : Super Admin public ID} {--reference= : Bank/remittance evidence reference} {--settled-at= : Date funds were received}';

    protected $description = 'Record independently verified network settlement evidence and re-evaluate the pending reward';

    public function handle(SettlementRecorder $recorder): int
    {
        if (! $this->option('actor') || ! $this->option('reference') || ! $this->option('settled-at')) {
            $this->error('Actor, reference and settled-at are required. Approval alone is not settlement.');

            return self::FAILURE;
        }
        $tx = AffiliateTransaction::where('public_id', $this->argument('transaction'))->firstOrFail();
        $actor = User::where('public_id', $this->option('actor'))->firstOrFail();
        $result = $recorder->record($tx, $actor, (string) $this->option('reference'), CarbonImmutable::parse((string) $this->option('settled-at')));
        $this->info('Settlement recorded; reward status: '.$result->reward_status);

        return self::SUCCESS;
    }
}
