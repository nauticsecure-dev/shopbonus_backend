<?php

namespace App\Console\Commands;

use App\Services\Support\SupportPolicy;
use App\Services\Support\SupportProvider;
use Illuminate\Console\Command;

class VerifySupportModel extends Command
{
    protected $signature = 'shopbonus:verify-support-model {--model= : Exact deployment-configured primary or fallback model}';

    protected $description = 'Spend one budgeted synthetic request to verify the exact configured support model tool contract';

    public function handle(SupportPolicy $policy, SupportProvider $provider): int
    {
        try {
            $current = $policy->current();
            if ($this->option('model')) {
                $current['model'] = (string) $this->option('model');
            }
            $provider->probe($current);
        } catch (\Throwable) {
            $this->error('Support model verification failed; no capability proof recorded. Check configuration and budget securely.');

            return self::FAILURE;
        }
        $this->info('Synthetic strict tool contract verified for the configured model/key/profile. This is not conversational quality acceptance.');

        return self::SUCCESS;
    }
}
