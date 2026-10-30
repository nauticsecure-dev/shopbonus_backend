<?php

namespace Tests\Feature\Cms;

use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherCodeStatus;
use App\Models\MerchantCommissionAgreement;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\WebshopPublicProfile;
use App\Services\Cms\CmsCache;
use App\Services\Cms\CmsService;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class HomepageCmsTest extends TestCase
{
    use CreatesActiveWebshops, InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_public_published_homepage_and_draft_invisible(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/homepage?locale=nl')->assertOk()->json('data');
        $this->assertSame('homepage', $state['key']);
        $this->assertNotNull($state['draft']);
        $this->assertNull($state['published']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/homepage?locale=nl')->assertNotFound();

        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/homepage/publish?locale=nl')->assertOk()->assertJsonPath('data.number', 1);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/homepage?locale=nl')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertJsonPath('data.content.title', 'ShopBonus')
            ->assertJsonPath('data.content.hero.heading', "Shop zoals je altijd doet.\nSpaar voor wat je écht wilt.");
    }

    public function test_admin_draft_preview_publish_restore_and_cache_invalidation(): void
    {
        $this->asAdmin();
        $rev = $this->getJson('/api/v1/admin/content/homepage?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/homepage/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => 'Nieuwe hero',
                'subtitle' => 'Subtitle',
                'primary_cta' => ['label' => 'Go', 'href' => '/webshops'],
                'trust' => ['A', 'B'],
                'promo_balance_example' => '€ 10',
            ],
        ])->assertOk()->assertJsonPath('data.draft_revision', $rev + 1);

        $this->postJson('/api/v1/admin/content/homepage/preview?locale=nl')
            ->assertOk()
            ->assertJsonPath('data.preview', true)
            ->assertJsonPath('data.content.hero.heading', 'Nieuwe hero')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->postJson('/api/v1/admin/content/homepage/publish?locale=nl')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/homepage?locale=nl')->assertJsonPath('data.content.hero.heading', 'Nieuwe hero');

        // Stale draft change must not affect public until publish; cache bump on publish.
        $this->asAdmin();
        $rev2 = $this->getJson('/api/v1/admin/content/homepage?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/homepage/sections/hero?locale=nl', [
            'revision' => $rev2,
            'section' => ['heading' => 'Alleen draft', 'primary_cta' => ['label' => 'X', 'href' => '/webshops']],
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/homepage?locale=nl')->assertJsonPath('data.content.hero.heading', 'Nieuwe hero');

        $this->asAdmin();
        $versions = $this->getJson('/api/v1/admin/content/homepage/versions?locale=nl')->assertOk()->json('data');
        $firstId = collect($versions)->firstWhere('number', 1)['id'];
        Cache::put('cms:'.(int) Cache::get('cms:version', 1).':homepage:nl', ['stale' => true], 300);
        $this->postJson('/api/v1/admin/content/homepage/restore', ['revision_id' => $firstId])
            ->assertOk()
            ->assertJsonPath('data.restored_from', 1);
        $this->app['auth']->forgetGuards();
        // restore publishes the restored content via CmsService::restore
        $this->getJson('/api/v1/content/homepage?locale=nl')->assertJsonPath('data.content.title', 'ShopBonus');
    }

    public function test_nl_and_en_drafts_are_independent(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/homepage/publish?locale=nl')->assertOk();
        $enRev = $this->getJson('/api/v1/admin/content/homepage?locale=en')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/homepage/sections/hero?locale=en', [
            'revision' => $enRev,
            'section' => ['heading' => 'English only', 'primary_cta' => ['label' => 'Go', 'href' => '/webshops']],
        ])->assertOk();
        $this->postJson('/api/v1/admin/content/homepage/publish?locale=en')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/homepage?locale=nl')->assertJsonPath('data.content.hero.heading', "Shop zoals je altijd doet.\nSpaar voor wat je écht wilt.");
        $this->getJson('/api/v1/content/homepage?locale=en')->assertJsonPath('data.content.hero.heading', 'English only');
    }

    public function test_non_admin_forbidden_and_xss_links_rejected(): void
    {
        $owner = User::factory()->create();
        $this->activeWebshop($owner);
        Sanctum::actingAs($owner);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time()]);
        $this->getJson('/api/v1/admin/content/homepage')->assertForbidden();

        $this->asAdmin();
        $rev = $this->getJson('/api/v1/admin/content/homepage?locale=nl')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/homepage/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => '<script>alert(1)</script>',
                'primary_cta' => ['label' => 'Go', 'href' => '/webshops'],
            ],
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/admin/content/homepage/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => 'Safe',
                'primary_cta' => ['label' => 'Go', 'href' => 'javascript:alert(1)'],
            ],
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/admin/content/homepage/sections/hero?locale=nl', [
            'revision' => $rev,
            'section' => [
                'heading' => 'Uitbetalen nu',
                'primary_cta' => ['label' => 'Go', 'href' => '/webshops'],
            ],
        ])->assertUnprocessable();
    }

    public function test_search_endpoint_basics(): void
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        WebshopPublicProfile::create([
            'webshop_id' => $shop->id,
            'slug' => 'modehuis-test',
            'display_name' => 'Modehuis Test',
            'short_description' => 'Mode die bij jou past',
            'published' => true,
            'published_at' => now(),
            'first_published_at' => now(),
        ]);

        $this->getJson('/api/v1/public/search?q=Mode&locale=nl')
            ->assertOk()
            ->assertJsonPath('data.groups.webshops.0.slug', 'modehuis-test')
            ->assertJsonPath('data.groups.webshops.0.href', '/webshops/modehuis-test');
    }

    public function test_discount_codes_only_reusable_and_cashback_label_uses_consumer_share(): void
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        WebshopPublicProfile::create([
            'webshop_id' => $shop->id,
            'slug' => 'cashback-shop',
            'display_name' => 'Cashback Shop',
            'short_description' => 'Spaar mee',
            'published' => true,
            'published_at' => now(),
            'first_published_at' => now(),
        ]);
        // 10% commission × 70% consumer share = 7% consumer cashback
        MerchantCommissionAgreement::create([
            'webshop_id' => $shop->id,
            'version' => 1,
            'commission_type' => 'percentage',
            'commission_rate_bps' => 1000,
            'consumer_share_bps' => 7000,
            'shopbonus_share_bps' => 3000,
            'cashback_approved' => true,
            'active' => true,
            'created_by' => $this->admin->id,
        ]);

        $member = $shop->memberships()->where('role', MembershipRole::Owner)->firstOrFail()->setRelation('webshop', $shop);
        $offers = app(OfferService::class);
        $unique = $offers->transition($offers->create($member, OfferAcquisition::PromotionalCode, [
            'name' => 'Unique secret',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'redemption_validity_days' => 30,
            'code_mode' => 'unique',
        ]), OfferStatus::Active);
        VoucherCode::create([
            'webshop_id' => $shop->id,
            'offer_id' => $unique->id,
            'code' => 'SECRET99',
            'code_hash' => VoucherCode::hashFor($shop->id, 'SECRET99'),
            'code_last4' => 'T99',
            'status' => VoucherCodeStatus::Available,
            'reusable' => false,
        ]);

        $reusable = $offers->transition($offers->create($member, OfferAcquisition::PromotionalCode, [
            'name' => 'Public coupon',
            'discount_type' => 'percentage',
            'discount_value' => 400,
            'redemption_validity_days' => 30,
            'code_mode' => 'reusable',
        ]), OfferStatus::Active);
        VoucherCode::create([
            'webshop_id' => $shop->id,
            'offer_id' => $reusable->id,
            'code' => 'SAVE4',
            'code_hash' => VoucherCode::hashFor($shop->id, 'SAVE4'),
            'code_last4' => 'AVE4',
            'status' => VoucherCodeStatus::Available,
            'reusable' => true,
        ]);

        $codes = $this->getJson('/api/v1/public/discount-codes?limit=10')->assertOk()->json('data');
        $this->assertCount(1, $codes);
        $this->assertSame('SAVE4', $codes[0]['code']);
        $this->assertSame('cashback-shop', $codes[0]['webshop']['slug']);

        $card = collect($this->getJson('/api/v1/public/webshops')->assertOk()->json('data'))
            ->firstWhere('slug', 'cashback-shop');
        $this->assertSame('Tot 7% cashback', $card['cashback_label']);
        $this->assertSame('Tot 7% cashback', $card['badge_label']);
        $this->assertStringNotContainsString('10%', (string) $card['cashback_label']);
    }

    public function test_ai_suggest_disabled_by_default(): void
    {
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/homepage/ai-suggest', ['section' => 'hero', 'locale' => 'nl'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'homepage_ai_not_configured');
    }

    public function test_ensure_homepage_via_service(): void
    {
        $page = app(CmsService::class)->ensureHomepage($this->admin->id);
        $this->assertSame('homepage', $page->key);
        $this->assertSame('landing', $page->type);
        $this->assertCount(2, $page->locales);
        CmsCache::flush();
    }
}
