<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Cms\CmsService;
use Illuminate\Database\Seeder;

/**
 * Local/demo webshop-owner landing CMS.
 *
 * Run: php artisan db:seed --class=MerchantLandingSeeder
 */
class MerchantLandingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('MerchantLandingSeeder is local/testing only.');

            return;
        }

        $admin = User::query()->orderBy('id')->first()
            ?? User::factory()->create(['email' => 'merchant-landing-seed@shopbonus.example.test', 'email_verified_at' => now()]);

        $page = app(CmsService::class)->ensureMerchantLanding($admin->id);
        foreach ($page->locales as $locale) {
            if ($locale->published_revision_id === null) {
                app(CmsService::class)->publish($page, $locale->locale, $admin->id);
            }
        }
        $this->command?->info('Merchant landing CMS ensured and published (NL+EN).');
    }
}
