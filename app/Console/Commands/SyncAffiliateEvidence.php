<?php

namespace App\Console\Commands;

use App\Jobs\SyncDaisyconEvidence;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use Illuminate\Console\Command;

class SyncAffiliateEvidence extends Command
{
    protected $signature = 'shopbonus:affiliate-evidence {connection : Daisycon connection public ID}';

    protected $description = 'Queue separate promotion, commission and access-rule snapshots for review';

    public function handle(): int
    {
        $connection = AffiliateConnection::where('public_id', $this->argument('connection'))->where('network', 'daisycon')->where('status', '!=', 'disabled')->firstOrFail();
        SyncDaisyconEvidence::dispatch($connection->id, 'promotions');
        foreach (AffiliateProgram::where('connection_id', $connection->id)->where('active', true)->cursor() as $program) {
            SyncDaisyconEvidence::dispatch($connection->id, 'commissions', $program->external_id);
            SyncDaisyconEvidence::dispatch($connection->id, 'access-rules', $program->external_id);
        }
        $this->info('Evidence jobs queued. Provider snapshots require review before publication.');

        return self::SUCCESS;
    }
}
