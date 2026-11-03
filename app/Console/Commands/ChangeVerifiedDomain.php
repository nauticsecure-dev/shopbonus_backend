<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Onboarding\DomainChange;
use Illuminate\Console\Command;

class ChangeVerifiedDomain extends Command
{
    protected $signature = 'shopbonus:change-verified-domain {webshop : Webshop public ID} {--actor= : Verified active Super Admin public ID} {--expected-domain= : Current verified domain} {--website= : New public website} {--reference= : Authorized change case reference} {--reset-verification : Confirm ownership and all current integrations will be reset}';

    protected $description = 'Explicitly move a verified domain back to onboarding and disconnect old integrations';

    public function handle(DomainChange $changes): int
    {
        foreach (['actor', 'expected-domain', 'website', 'reference', 'reset-verification'] as $option) {
            if (! $this->option($option)) {
                $this->error('Actor, expected-domain, website, reference and reset-verification are required.');

                return self::FAILURE;
            }
        }
        $shop = Webshop::where('public_id', $this->argument('webshop'))->first();
        $actor = User::where('public_id', $this->option('actor'))->first();
        if ($shop === null || $actor === null) {
            $this->error('Webshop or actor not found.');

            return self::FAILURE;
        }
        try {
            $changes->change($shop, $actor, (string) $this->option('expected-domain'), (string) $this->option('website'), (string) $this->option('reference'));
        } catch (ApiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Domain changed. Reconfirm business details, prove new-domain ownership and create/verify new integrations.');

        return self::SUCCESS;
    }
}
