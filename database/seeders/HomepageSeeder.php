<?php

namespace Database\Seeders;

use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Cms\CmsService;
use App\Services\Offers\OfferService;
use Illuminate\Database\Seeder;

/**
 * Local/demo homepage CMS + one public reusable promo code.
 *
 * Run: php artisan db:seed --class=HomepageSeeder
 * Never invoked by DatabaseSeeder (production-safe).
 */
class HomepageSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('HomepageSeeder is local/testing only.');

            return;
        }

        $admin = User::query()->orderBy('id')->first()
            ?? User::factory()->create(['email' => 'homepage-seed@shopbonus.example.test', 'email_verified_at' => now()]);

        $page = app(CmsService::class)->ensureHomepage($admin->id);
        foreach ($page->locales as $locale) {
            if ($locale->published_revision_id === null) {
                app(CmsService::class)->publish($page, $locale->locale, $admin->id);
            }
        }
        $this->command?->info('Homepage CMS ensured and published (NL+EN).');

        $profile = WebshopPublicProfile::where('published', true)->orderBy('id')->first();
        if ($profile === null) {
            $this->command?->warn('No published webshop; skip reusable promo code. Run DiscoveryDirectorySeeder first.');

            return;
        }

        $shop = Webshop::findOrFail($profile->webshop_id);
        $membership = $shop->memberships()->where('role', MembershipRole::Owner)->first()?->setRelation('webshop', $shop);
        if ($membership === null) {
            $this->command?->warn('No owner membership for reusable promo.');

            return;
        }

        $existing = VoucherCode::query()->where('reusable', true)->where('webshop_id', $shop->id)->exists();
        if ($existing) {
            $this->command?->info('Reusable promo code already present.');

            return;
        }

        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($membership, OfferAcquisition::PromotionalCode, [
            'name' => 'Homepage demo korting',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'min_order_minor' => 2500,
            'redemption_validity_days' => 90,
            'code_mode' => 'reusable',
        ]), OfferStatus::Active);

        $plain = 'WELKOM10';
        VoucherCode::create([
            'webshop_id' => $shop->id,
            'offer_id' => $offer->id,
            'code' => $plain,
            'code_hash' => VoucherCode::hashFor($shop->id, $plain),
            'code_last4' => substr($plain, -4),
            'status' => VoucherCodeStatus::Available,
            'reusable' => true,
        ]);

        $this->command?->info("Reusable promo {$plain} seeded on {$profile->slug}.");
    }
}
