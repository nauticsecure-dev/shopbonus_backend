<?php

namespace App\Console\Commands;

use App\Models\AffiliateProgram;
use App\Models\Offer;
use App\Models\User;
use App\Services\Affiliate\PromotionPermissionRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RecordPromotionPermission extends Command
{
    protected $signature = 'shopbonus:affiliate-promotion-permission {offer : Offer public ID} {program : Program public ID} {--actor= : Super Admin public ID} {--reference= : Advertiser permission reference} {--terms= : Applicable code and exclusions} {--valid-until= : Permission expiry}';

    protected $description = 'Record reviewed advertiser permission that this promotional code preserves cashback';

    public function handle(PromotionPermissionRecorder $recorder): int
    {
        foreach (['actor', 'reference', 'terms', 'valid-until'] as $option) {
            if (! $this->option($option)) {
                $this->error('Actor, advertiser reference, applicable terms and expiry are required.');

                return self::FAILURE;
            }
        }
        $offer = $recorder->record(
            Offer::where('public_id', $this->argument('offer'))->firstOrFail(),
            AffiliateProgram::where('public_id', $this->argument('program'))->firstOrFail(),
            User::where('public_id', $this->option('actor'))->firstOrFail(),
            (string) $this->option('reference'), (string) $this->option('terms'), CarbonImmutable::parse((string) $this->option('valid-until')),
        );
        $this->info('Permission recorded on offer version '.$offer->currentVersion->version.'.');

        return self::SUCCESS;
    }
}
