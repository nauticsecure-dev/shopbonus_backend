<?php

namespace Database\Seeders;

use App\Enums\AgreementTemplateStatus;
use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\WebshopStatus;
use App\Models\AgreementTemplate;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Funding\MerchantFunding;
use App\Services\Offers\OfferService;
use App\Services\Wallet\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local demo accounts: Super Admin, consumers, shop owners + approved shops
 * with agreement, funding, public profile, offers and voucher codes.
 *
 * Run:
 *   php artisan db:seed --class=DemoUsersAndShopsSeeder
 *   php artisan migrate:fresh --seed   (local only; see DatabaseSeeder)
 *
 * Never runs in production.
 */
class DemoUsersAndShopsSeeder extends Seeder
{
    /** Shared local password for all demo accounts (not for production). */
    public const PASSWORD = 'ShopBonus-Local-2026!';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('DemoUsersAndShopsSeeder is local/testing only.');

            return;
        }

        $template = $this->ensureAgreementTemplate();

        $admin = $this->ensureUser([
            'email' => 'superadmin@shopbonus.local',
            'first_name' => 'Super Admin',
            'onboarding_intent' => 'consumer',
        ], superAdmin: true);

        $consumer = $this->ensureUser([
            'email' => 'consumer@shopbonus.local',
            'first_name' => 'Sanne',
            'onboarding_intent' => 'consumer',
        ]);

        $consumerTwo = $this->ensureUser([
            'email' => 'anna@shopbonus.local',
            'first_name' => 'Anna',
            'onboarding_intent' => 'consumer',
        ]);

        $ownerWoon = $this->ensureUser([
            'email' => 'merchant@shopbonus.local',
            'first_name' => 'Merchant',
            'onboarding_intent' => 'merchant',
        ]);

        $ownerTuin = $this->ensureUser([
            'email' => 'tuinplus@shopbonus.local',
            'first_name' => 'TuinPlus Owner',
            'onboarding_intent' => 'merchant',
        ]);

        $woon = $this->ensureApprovedShop($ownerWoon, $template, [
            'name' => 'Woon & Co',
            'slug' => 'woon-en-co-demo',
            'short' => 'Alles voor een stijlvol thuis.',
            'description' => 'Demo webshop voor ShopBonus merchant dashboard en discovery.',
            'categories' => ['home_living'],
            'website' => 'https://woon-en-co.example.test',
            'cover' => 'https://images.pexels.com/photos/1571460/pexels-photo-1571460.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'offers' => 3,
            'codes_per_offer' => 10,
        ]);

        $tuin = $this->ensureApprovedShop($ownerTuin, $template, [
            'name' => 'TuinPlus',
            'slug' => 'tuinplus-demo',
            'short' => 'Alles voor tuin en balkon.',
            'description' => 'Tweede demo webshop voor multi-owner scenarios.',
            'categories' => ['sports_outdoor', 'home_living'],
            'website' => 'https://tuinplus.example.test',
            'cover' => 'https://images.pexels.com/photos/1301856/pexels-photo-1301856.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'offers' => 2,
            'codes_per_offer' => 8,
        ]);

        $pending = $this->ensurePendingShop($ownerTuin, [
            'name' => 'Nieuw Merk Pending',
            'slug' => 'nieuw-merk-pending',
        ]);

        // Extra directory shops + CMS content (idempotent skips).
        $this->call(DiscoveryDirectorySeeder::class);
        $this->call(HomepageSeeder::class);
        $this->call(AboutUsSeeder::class);
        $this->call(MerchantLandingSeeder::class);

        $this->command?->newLine();
        $this->command?->info('=== ShopBonus local demo accounts ===');
        $this->command?->table(
            ['Role', 'Email', 'Password', 'Notes'],
            [
                ['Super Admin', $admin->email, self::PASSWORD, '→ /admin (2FA off, ADMIN_MFA_REQUIRED=false recommended)'],
                ['Consumer', $consumer->email, self::PASSWORD, '→ /mijn-voordelen'],
                ['Consumer', $consumerTwo->email, self::PASSWORD, '→ /mijn-voordelen'],
                ['Shop owner', $ownerWoon->email, self::PASSWORD, "Workspace: {$woon->name}"],
                ['Shop owner', $ownerTuin->email, self::PASSWORD, "Workspaces: {$tuin->name} + pending {$pending->name}"],
            ]
        );
        $this->command?->warn('Local/demo only — never use these credentials in production.');
    }

    /**
     * @param  array{email: string, first_name: string, onboarding_intent: string}  $attrs
     */
    private function ensureUser(array $attrs, bool $superAdmin = false): User
    {
        $user = User::firstOrCreate(
            ['email' => $attrs['email']],
            [
                'first_name' => $attrs['first_name'],
                'password' => self::PASSWORD,
                'locale' => 'nl',
                'onboarding_intent' => $attrs['onboarding_intent'],
            ]
        );

        $user->forceFill([
            'first_name' => $attrs['first_name'],
            'password' => self::PASSWORD, // hashed cast
            'email_verified_at' => $user->email_verified_at ?? now(),
            'status' => UserStatus::Active,
            'onboarding_intent' => $attrs['onboarding_intent'],
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if ($superAdmin && ! $user->isSuperAdmin()) {
            $user->platformRoles()->create([
                'role' => PlatformRole::SuperAdmin,
                'granted_via' => 'console',
                'granted_at' => now(),
            ]);
        }

        return $user->fresh();
    }

    private function ensureAgreementTemplate(): AgreementTemplate
    {
        $existing = AgreementTemplate::where('key', AgreementTemplate::MERCHANT)
            ->where('status', AgreementTemplateStatus::Published)
            ->first();

        if ($existing) {
            return $existing;
        }

        $creator = User::query()->orderBy('id')->first()
            ?? User::factory()->create(['email' => 'seed-system@shopbonus.local', 'email_verified_at' => now()]);

        return AgreementTemplate::create([
            'key' => AgreementTemplate::MERCHANT,
            'version' => 'demo-seed-1',
            'locale' => 'nl',
            'title' => 'ShopBonus merchant agreement (demo)',
            'body' => 'Demo merchant agreement for {{legal_name}}. Local seed only.',
            'status' => AgreementTemplateStatus::Published,
            'published_at' => now(),
            'created_by' => $creator->id,
        ]);
    }

    /**
     * @param  array{
     *   name: string,
     *   slug: string,
     *   short: string,
     *   description: string,
     *   categories: list<string>,
     *   website: string,
     *   cover: string,
     *   offers: int,
     *   codes_per_offer: int
     * }  $def
     */
    private function ensureApprovedShop(User $owner, AgreementTemplate $template, array $def): Webshop
    {
        if (WebshopPublicProfile::where('slug', $def['slug'])->exists()) {
            $shop = WebshopPublicProfile::where('slug', $def['slug'])->firstOrFail()->webshop;
            $this->command?->info("Skip existing shop: {$def['slug']}");

            return $shop;
        }

        $shop = Webshop::factory()->approved()->withMember($owner, MembershipRole::Owner)->create([
            'name' => $def['name'],
            'created_by' => $owner->id,
            'ownership_verified_at' => now(),
            'ownership_method' => 'dns_txt',
            'platform' => 'woocommerce',
        ]);

        BusinessProfile::query()->updateOrCreate(
            ['webshop_id' => $shop->id],
            [
                'legal_name' => $def['name'].' B.V.',
                'trading_name' => $def['name'],
                'website_url' => $def['website'],
                'website_domain' => parse_url($def['website'], PHP_URL_HOST) ?: $def['slug'].'.example.test',
                'contact_email' => $owner->email,
                'address_line' => 'Demostraat 1',
                'postal_code' => '1234AB',
                'city' => 'Amsterdam',
                'country_code' => 'NL',
                'kvk_number' => '12345678',
                'preferred_language' => 'nl',
                'source' => 'manual',
                'confirmed_at' => now(),
                'confirmed_by' => $owner->id,
            ]
        );

        $shop->agreementAcceptances()->create([
            'agreement_template_id' => $template->id,
            'version' => $template->version,
            'rendered_body' => str_replace('{{legal_name}}', $def['name'].' B.V.', $template->body),
            'document_hash' => hash('sha256', $template->body.$def['slug']),
            'signatory_name' => $owner->first_name,
            'accepted_by' => $owner->id,
            'accepted_at' => now(),
        ]);

        app(WalletService::class)->credit(
            app(MerchantFunding::class)->wallet($shop),
            1_000_000,
            'demo-seed-funding:'.$shop->id,
            neverExpires: true
        );

        WebshopPublicProfile::create([
            'webshop_id' => $shop->id,
            'slug' => $def['slug'],
            'display_name' => $def['name'],
            'short_description' => $def['short'],
            'description' => $def['description'],
            'website_url' => $def['website'],
            'cover_url' => $def['cover'],
            'published' => true,
            'published_at' => now(),
            'first_published_at' => now(),
        ]);

        DB::table('webshop_public_categories')->insert(array_map(
            fn (string $c) => ['webshop_id' => $shop->id, 'category' => $c],
            $def['categories']
        ));

        $membership = $shop->memberships()->where('role', MembershipRole::Owner)->sole()->setRelation('webshop', $shop);
        $offers = app(OfferService::class);
        for ($i = 1; $i <= $def['offers']; $i++) {
            $payload = [
                'name' => "{$def['name']} voordeel {$i}",
                'discount_type' => $i % 2 === 0 ? 'percentage' : 'fixed',
                'discount_value' => $i % 2 === 0 ? 1000 : 500 * $i,
                'min_order_minor' => 2500,
                'redemption_validity_days' => 90,
            ];
            if ($payload['discount_type'] === 'percentage') {
                $payload['max_discount_minor'] = 2500;
            }
            $offer = $offers->transition(
                $offers->create($membership, OfferAcquisition::MerchantReward, $payload),
                OfferStatus::Active
            );

            for ($c = 1; $c <= $def['codes_per_offer']; $c++) {
                $code = strtoupper(substr(md5($def['slug']."-{$i}-{$c}"), 0, 12));
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

        $this->command?->info("Seeded approved shop {$def['name']} ({$def['slug']}).");

        return $shop;
    }

    /** @param  array{name: string, slug: string}  $def */
    private function ensurePendingShop(User $owner, array $def): Webshop
    {
        $existing = Webshop::query()->where('name', $def['name'])->where('status', WebshopStatus::PendingReview)->first();
        if ($existing) {
            $this->command?->info("Skip existing pending shop: {$def['name']}");

            return $existing;
        }

        $shop = Webshop::factory()->withMember($owner, MembershipRole::Owner)->create([
            'name' => $def['name'],
            'status' => WebshopStatus::PendingReview,
            'created_by' => $owner->id,
            'review_requested_at' => now()->subHour(),
            'ownership_verified_at' => now(),
            'ownership_method' => 'dns_txt',
        ]);

        $this->command?->info("Seeded pending shop {$def['name']} (awaiting Super Admin approval).");

        return $shop;
    }
}
