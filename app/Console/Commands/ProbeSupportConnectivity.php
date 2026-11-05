<?php

namespace App\Console\Commands;

use App\Services\Support\SupportConnectivity;
use Illuminate\Console\Command;

class ProbeSupportConnectivity extends Command
{
    protected $signature = 'shopbonus:probe-support-connectivity';

    protected $description = 'Measure a private-channel Reverb nonce round trip (no customer data)';

    public function handle(SupportConnectivity $probe): int
    {
        $result = $probe->run();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return in_array($result['reverb_live_probe'], ['connected', 'not_configured'], true) ? self::SUCCESS : self::FAILURE;
    }
}
