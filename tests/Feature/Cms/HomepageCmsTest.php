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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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
        $this->postJson('/api/v1/admin/content/homepage/publish?locale=nl', ['revision' => $this->getJson('/api/v1/admin/content/homepage?locale=nl')->json('data.draft_revision')])->assertOk()->assertJsonPath('data.number', 1);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/content/homepage?locale=nl')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
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

        $this->postJson('/api/v1/admin/content/homepage/publish?locale=nl', ['revision' => $this->getJson('/api/v1/admin/content/homepage?locale=nl')->json('data.draft_revision')])->assertOk();
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
        $this->postJson('/api/v1/admin/content/homepage/publish?locale=nl', ['revision' => $this->getJson('/api/v1/admin/content/homepage?locale=nl')->json('data.draft_revision')])->assertOk();
        $enRev = $this->getJson('/api/v1/admin/content/homepage?locale=en')->json('data.draft_revision');
        $this->patchJson('/api/v1/admin/content/homepage/sections/hero?locale=en', [
            'revision' => $enRev,
            'section' => ['heading' => 'English only', 'primary_cta' => ['label' => 'Go', 'href' => '/webshops']],
        ])->assertOk();
        $this->postJson('/api/v1/admin/content/homepage/publish?locale=en', ['revision' => $this->getJson('/api/v1/admin/content/homepage?locale=en')->json('data.draft_revision')])->assertOk();

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

    public function test_extended_homepage_sections_and_responsive_images_are_validated(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/homepage?locale=en')->json('data');
        $content = $state['draft'];
        $content['section_order'] = ['search', 'hero', 'benefits', 'cta_band', 'faq_teaser'];
        $content['benefits'] = ['heading' => 'Benefits', 'consumer_title' => 'Consumers', 'consumer_items' => ['Save credit'], 'merchant_title' => 'Merchants', 'merchant_items' => ['Reach shoppers']];
        $content['cta_band'] = ['heading' => 'Start', 'subtitle' => 'Join us', 'consumer_cta' => ['label' => 'Join', 'href' => '/account'], 'merchant_cta' => ['label' => 'Register', 'href' => '/voor-webshops']];
        $content['faq_teaser'] = ['heading' => 'FAQ', 'subtitle' => 'Answers', 'cta_label' => 'Read more', 'cta_href' => '/faq'];
        Storage::fake('local');
        $asset = $this->post('/api/v1/admin/content/homepage/assets', ['image' => UploadedFile::fake()->image('hero.png', 100, 100)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $content['hero']['background_mobile_asset_id'] = $asset['id'];
        $this->putJson('/api/v1/admin/content/homepage?locale=en', ['revision' => $state['draft_revision'], 'content' => $content])->assertOk();
        $this->postJson('/api/v1/admin/content/homepage/publish?locale=en', ['revision' => $this->getJson('/api/v1/admin/content/homepage?locale=en')->json('data.draft_revision')])->assertOk();
        $this->getJson('/api/v1/content/homepage?locale=en')->assertOk()->assertJsonPath('data.content.hero.background_mobile_url', $asset['url'])->assertJsonPath('data.content.section_order.0', 'search');
        $revision = $this->getJson('/api/v1/admin/content/homepage?locale=en')->json('data.draft_revision');
        $content['faq_teaser']['cta_href'] = 'javascript:alert(1)';
        $this->putJson('/api/v1/admin/content/homepage?locale=en', ['revision' => $revision, 'content' => $content])->assertUnprocessable();
        $content['faq_teaser']['cta_href'] = '/faq';
        $content['hero']['background_mobile_asset_id'] = 'unknown-asset';
        $this->putJson('/api/v1/admin/content/homepage?locale=en', ['revision' => $revision, 'content' => $content])->assertUnprocessable();
    }

    public function test_cached_homepage_cannot_outlive_its_schedule(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/homepage?locale=nl')->json('data');
        $content = $state['draft'];
        $content['schedule'] = ['publish_at' => now()->subMinute()->toIso8601String(), 'unpublish_at' => now()->addSeconds(10)->toIso8601String()];
        $this->putJson('/api/v1/admin/content/homepage?locale=nl', ['revision' => $state['draft_revision'], 'content' => $content])->assertOk();
        $this->postJson('/api/v1/admin/content/homepage/publish?locale=nl', ['revision' => $this->getJson('/api/v1/admin/content/homepage?locale=nl')->json('data.draft_revision')])->assertOk();
        $this->getJson('/api/v1/content/homepage?locale=nl')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->travel(11)->seconds();
        $this->getJson('/api/v1/content/homepage?locale=nl')->assertNotFound();
    }

    public function test_publication_requires_reviewed_revision_and_rejects_concurrent_draft_changes(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/homepage')->json('data');
        $this->postJson('/api/v1/admin/content/homepage/publish')->assertUnprocessable();
        $this->postJson('/api/v1/admin/content/homepage/publish', ['revision' => $state['draft_revision']])->assertOk();
        $original = $this->getJson('/api/v1/content/homepage')->json('data');
        $content = $state['draft'];
        $content['hero']['heading'] = 'Concurrent unreviewed edit';
        $otherAdmin = User::factory()->create();
        $otherAdmin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        Sanctum::actingAs($otherAdmin);
        $this->putJson('/api/v1/admin/content/homepage', ['revision' => $state['draft_revision'], 'content' => $content])->assertOk();
        $this->asAdmin();
        $this->postJson('/api/v1/admin/content/homepage/publish', ['revision' => $state['draft_revision']])
            ->assertConflict()->assertJsonPath('error.code', 'draft_changed');
        $this->assertSame($original, $this->getJson('/api/v1/content/homepage')->json('data'));
        $this->getJson('/api/v1/admin/content/homepage/versions?locale=nl')->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/admin/content/homepage/publish', ['revision' => $state['draft_revision'] + 1])->assertOk();
        $this->getJson('/api/v1/content/homepage')->assertJsonPath('data.content.hero.heading', 'Concurrent unreviewed edit');
    }

    public function test_queued_homepage_replacement_preserves_live_content_and_rejects_later_edits(): void
    {
        $this->asAdmin();
        $state = $this->getJson('/api/v1/admin/content/homepage')->json('data');
        $this->postJson('/api/v1/admin/content/homepage/publish', ['revision' => $state['draft_revision']])->assertOk();
        $live = $this->getJson('/api/v1/content/homepage')->json('data.content.hero.heading');
        $content = $state['draft'];
        $content['hero']['heading'] = 'Scheduled replacement';
        $rev = $this->putJson('/api/v1/admin/content/homepage', ['revision' => $state['draft_revision'], 'content' => $content])->assertOk()->json('data.draft_revision');
        $url = '/api/v1/admin/cms/pages/'.$state['id'].'/schedules';
        $this->postJson($url, ['action' => 'publish', 'locale' => 'nl', 'revision' => $rev, 'due_at' => now()->addMinutes(2)->toIso8601String()])->assertCreated();
        $this->getJson('/api/v1/content/homepage')->assertJsonPath('data.content.hero.heading', $live);
        $this->travel(3)->minutes();
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->getJson('/api/v1/content/homepage')->assertJsonPath('data.content.hero.heading', 'Scheduled replacement');
        $scheduled = $this->postJson($url, ['action' => 'publish', 'locale' => 'nl', 'revision' => $rev, 'due_at' => now()->addMinutes(2)->toIso8601String()])->assertCreated()->json('data.id');
        $content['hero']['heading'] = 'Unreviewed later edit';
        $this->putJson('/api/v1/admin/content/homepage', ['revision' => $rev, 'content' => $content])->assertOk();
        $this->travel(3)->minutes();
        $this->artisan('shopbonus:publish-scheduled-content')->assertSuccessful();
        $this->assertDatabaseHas('cms_publication_schedules', ['public_id' => $scheduled, 'status' => 'stale']);
        $this->getJson('/api/v1/content/homepage')->assertJsonPath('data.content.hero.heading', 'Scheduled replacement');
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
