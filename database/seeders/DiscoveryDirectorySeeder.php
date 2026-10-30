<?php

namespace Database\Seeders;

use App\Enums\AgreementTemplateStatus;
use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Models\AgreementTemplate;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Funding\MerchantFunding;
use App\Services\Offers\OfferService;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local/demo directory shops matching the consumer discovery design.
 *
 * Run: php artisan db:seed --class=DiscoveryDirectorySeeder
 * Never invoked by DatabaseSeeder (production-safe).
 */
class DiscoveryDirectorySeeder extends Seeder
{
    /** @var list<array{slug: string, name: string, short: string, description: string, categories: list<string>, cover: string, offers: int}> */
    private const SHOPS = [
        [
            'slug' => 'woon-en-co',
            'name' => 'Woon & Co',
            'short' => 'Alles voor een stijlvol thuis.',
            'description' => 'Alles voor een stijlvol thuis. Ontdek meubels, decoratie en lifestyle bij Woon & Co.',
            'categories' => ['home_living'],
            'cover' => 'https://images.pexels.com/photos/1571460/pexels-photo-1571460.jpeg?auto=compress&cs=tinysrgb&w=1200&h=800&fit=crop',
            'offers' => 3,
        ],
        [
            'slug' => 'modehuis',
            'name' => 'Modehuis',
            'short' => 'Mode die bij jou past.',
            'description' => 'Duurzame mode en seizoenscollecties voor elke look.',
            'categories' => ['fashion'],
            'cover' => 'https://images.pexels.com/photos/5706273/pexels-photo-5706273.jpeg?auto=compress&cs=tinysrgb&w=1200&h=800&fit=crop',
            'offers' => 3,
        ],
        [
            'slug' => 'techwinkel',
            'name' => 'Techwinkel',
            'short' => 'Slimme tech voor elke dag.',
            'description' => 'Laptops, audio en gadgets met deskundig advies.',
            'categories' => ['electronics'],
            'cover' => 'https://images.pexels.com/photos/4006158/pexels-photo-4006158.jpeg?auto=compress&cs=tinysrgb&w=1200&h=800&fit=crop',
            'offers' => 3,
        ],
        [
            'slug' => 'beauty-studio',
            'name' => 'Beauty Studio',
            'short' => 'Glow met de juiste verzorging.',
            'description' => 'Skincare, make-up en wellness essentials.',
            'categories' => ['beauty_health'],
            'cover' => 'https://images.pexels.com/photos/3373736/pexels-photo-3373736.jpeg?auto=compress&cs=tinysrgb&w=1200&h=800&fit=crop',
            'offers' => 2,
        ],
        [
            'slug' => 'sportlab',
            'name' => 'Sportlab',
            'short' => 'Train harder, voel je beter.',
            'description' => 'Sportkleding, sneakers en fitness gear.',
            'categories' => ['sports_outdoor'],
            'cover' => 'https://images.pexels.com/photos/28271086/pexels-photo-28271086.jpeg?auto=compress&cs=tinysrgb&w=1200&h=800&fit=crop',
            'offers' => 3,
        ],
        [
            'slug' => 'keukenhuis',
            'name' => 'Keukenhuis',
            'short' => 'Alles voor de perfecte keuken.',
            'description' => 'Pannen, bestek en keukenapparatuur van topmerken.',
            'categories' => ['home_living', 'food_drinks'],
            'cover' => 'https://images.pexels.com/photos/5824883/pexels-photo-5824883.jpeg?auto=compress&cs=tinysrgb&w=1200&h=800&fit=crop',
            'offers' => 2,
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('DiscoveryDirectorySeeder is local/testing only.');

            return;
        }

        $owner = User::firstOrCreate(
            ['email' => 'discovery-merchant@shopbonus.example.test'],
            [
                'first_name' => 'Discovery Merchant',
                'password' => 'DiscoveryShop-Local-Only-'.Str::random(16),
                'locale' => 'nl',
                'onboarding_intent' => 'merchant',
            ]
        );
        if ($owner->email_verified_at === null) {
            $owner->forceFill(['email_verified_at' => now()])->save();
        }

        $template = AgreementTemplate::where('key', AgreementTemplate::MERCHANT)
            ->where('status', AgreementTemplateStatus::Published)
            ->first()
            ?? AgreementTemplate::create([
                'key' => AgreementTemplate::MERCHANT,
                'version' => 'discovery-seed-1',
                'locale' => 'nl',
                'title' => 'Discovery seed agreement',
                'body' => 'Local discovery seed for {{legal_name}}',
                'status' => AgreementTemplateStatus::Published,
                'published_at' => now(),
                'created_by' => $owner->id,
            ]);

        foreach (self::SHOPS as $def) {
            if (WebshopPublicProfile::where('slug', $def['slug'])->exists()) {
                $this->command?->info("Skip existing: {$def['slug']}");

                continue;
            }

            $shop = Webshop::factory()->approved()->withMember($owner, MembershipRole::Owner)->create([
                'name' => $def['name'],
                'created_by' => $owner->id,
                'ownership_verified_at' => now(),
                'ownership_method' => 'dns_txt',
            ]);

            $shop->agreementAcceptances()->create([
                'agreement_template_id' => $template->id,
                'version' => $template->version,
                'rendered_body' => 'Discovery seed agreement',
                'document_hash' => hash('sha256', 'Discovery seed agreement'),
                'signatory_name' => 'Discovery Merchant',
                'accepted_by' => $owner->id,
                'accepted_at' => now(),
            ]);

            app(WalletService::class)->credit(
                app(MerchantFunding::class)->wallet($shop),
                1_000_000,
                'discovery-seed-funding:'.$shop->id,
                neverExpires: true
            );

            WebshopPublicProfile::create([
                'webshop_id' => $shop->id,
                'slug' => $def['slug'],
                'display_name' => $def['name'],
                'short_description' => $def['short'],
                'description' => $def['description'],
                'website_url' => 'https://'.$def['slug'].'.example.test',
                'cover_url' => $def['cover'],
                'published' => true,
                'published_at' => now(),
                'first_published_at' => now(),
            ]);

            DB::table('webshop_public_categories')->insert(array_map(
                fn (string $c) => ['webshop_id' => $shop->id, 'category' => $c],
                $def['categories']
            ));

            $membership = $shop->memberships()->where('role', MembershipRole::Owner)->firstOrFail()->setRelation('webshop', $shop);
            $offers = app(OfferService::class);
            for ($i = 1; $i <= $def['offers']; $i++) {
                $offer = $offers->transition($offers->create($membership, OfferAcquisition::PromotionalCode, [
                    'name' => "{$def['name']} voordeel {$i}",
                    'discount_type' => $i % 2 === 0 ? 'percentage' : 'fixed',
                    'discount_value' => $i % 2 === 0 ? 1000 + ($i * 100) : 500 * $i,
                    'min_order_minor' => 2500,
                    'redemption_validity_days' => 90,
                    'max_discount_minor' => $i % 2 === 0 ? 2500 : null,
                ]), OfferStatus::Active);

                for ($c = 1; $c <= 5; $c++) {
                    $code = strtoupper(substr(md5($def['slug'].$i.$c), 0, 10));
                    VoucherCode::create([
                        'webshop_id' => $shop->id,
                        'offer_id' => $offer->id,
                        'code' => $code,
                        'code_hash' => VoucherCode::hashFor($shop->id, $code),
                        'code_last4' => substr($code, -4),
                        'status' => VoucherCodeStatus::Available,
                    ]);
                }
            }

            $this->command?->info("Seeded {$def['name']} ({$def['slug']}) with {$def['offers']} offers.");
        }

        // Homepage CMS + one reusable public coupon for local demos.
        $this->call(HomepageSeeder::class);
    }
}
