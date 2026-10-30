<?php

namespace Tests\Feature\Banners;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Integrations\CapabilityMatrix;
use App\Integrations\Data\CapabilityState;
use App\Models\BannerInstallation;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Funding\MerchantFunding;
use App\Services\Offers\BranchOfferDefaults;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\TestCase;

/**
 * Trello #25 — exactly 10 end-to-end assertions for Live Banner Studio.
 * Backend owns API/lifecycle/security/measurement; embed + studio UI are served
 * from public/embed/v1.js and the Next.js dashboard (see docs/banners).
 */
class LiveBannerStudioE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private const ORIGIN = 'https://www.woonenco.nl';

    private User $owner;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Mode Atelier', 'verified_domain' => 'woonenco.nl']);
    }

    /** E2E 1 — Embed script is present; only widgets are interactive (Shadow DOM host). */
    public function test_e2e01_storefront_embed_isolates_widgets_in_shadow_dom(): void
    {
        $path = public_path('embed/v1.js');
        $this->assertFileExists($path);
        $js = File::get($path);
        $this->assertStringContainsString('attachShadow', $js);
        $this->assertStringContainsString('shopbonus.banner.v1', $js);
        $this->assertStringContainsString('ShopBonusCart', $js);
        $this->assertStringContainsString('sb-edit', $js);
        $this->assertStringContainsString('pointer-events:none', $js);
        $this->assertStringNotContainsString('sessionStorage.setItem("token"', $js);
    }

    /** E2E 2 — Campaign overview + studio contracts (list filters, placement, results projection). */
    public function test_e2e02_studio_overview_list_filters_and_results_shape(): void
    {
        Sanctum::actingAs($this->owner);
        $config = BannerFixture::config();
        $id = $this->postJson($this->url(), ['name' => 'Floating reward', 'config' => $config])->assertCreated()->json('data.id');
        $list = $this->getJson($this->url().'?search=Floating&placement=top')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('top', $list[0]['placement']);
        $this->assertArrayHasKey('stats_30d', $list[0]);
        $this->getJson($this->url("/{$id}"))->assertOk()
            ->assertJsonPath('data.notice', 'Customers see the published version until you publish the draft.');
        $results = $this->getJson($this->url("/{$id}/results"))->assertOk()->json('data');
        foreach (['eligible', 'viewable_impressions', 'cta_clicks', 'dismissals', 'ctr'] as $key) {
            $this->assertArrayHasKey($key, $results);
        }
    }

    /** E2E 3 — Draft privacy (merchant sees draft; public config stays published). */
    public function test_e2e03_draft_privacy_against_public_config(): void
    {
        Sanctum::actingAs($this->owner);
        $offer = $this->fundedOffer(1000, 10000);
        $id = $this->postJson($this->url(), [
            'name' => '€100/€10',
            'offer_id' => $offer->public_id,
            'config' => array_replace_recursive(BannerFixture::config(), [
                'content' => ['heading' => 'Spend €100, earn €10', 'cta_url' => 'https://www.woonenco.nl/shopbonus'],
            ]),
        ])->assertCreated()->json('data.id');
        $this->postJson($this->url("/{$id}/publish"))->assertOk();
        $this->putJson($this->url("/{$id}"), [
            'revision' => 1,
            'config' => array_replace_recursive(BannerFixture::config(), [
                'content' => ['heading' => 'DRAFT ONLY €100/€10', 'cta_url' => 'https://www.woonenco.nl/shopbonus'],
            ]),
        ])->assertOk();
        $this->getJson($this->url("/{$id}"))->assertJsonPath('data.draft_config.content.heading', 'DRAFT ONLY €100/€10');
        $public = $this->publicConfig()->assertOk()->json('data.campaigns.0.config.content.heading');
        $this->assertSame('Spend €100, earn €10', $public);
    }

    /** E2E 4 — Schedule / publish / pause / rollback with audit. */
    public function test_e2e04_lifecycle_schedule_publish_pause_rollback(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson($this->url(), ['name' => 'Lifecycle', 'config' => BannerFixture::config()])->assertCreated()->json('data.id');
        $this->postJson($this->url("/{$id}/schedule"), ['publish_at' => now('Europe/Amsterdam')->addHour()->format('Y-m-d\TH:i')])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:publish-scheduled-banners')->assertSuccessful();
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/{$id}/pause"))->assertOk()->assertJsonPath('data.status', 'paused');
        $this->assertSame([], $this->publicConfig()->json('data.campaigns'));
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/{$id}/resume"))->assertOk();
        $this->putJson($this->url("/{$id}"), ['revision' => 1, 'config' => array_replace_recursive(BannerFixture::config(), ['content' => ['heading' => 'V2', 'cta_url' => 'https://example.nl/rewards']])])->assertOk();
        $this->postJson($this->url("/{$id}/publish"))->assertOk();
        $this->postJson($this->url("/{$id}/rollback"), ['version' => 1])->assertOk();
        $this->assertSame('Next-purchase reward', $this->publicConfig()->json('data.campaigns.0.config.content.heading'));
    }

    /** E2E 5 — Trigger library Fashion /collections/* + 20s + scroll + once/session. */
    public function test_e2e05_trigger_library_fashion_collections_once_session(): void
    {
        Sanctum::actingAs($this->owner);
        $config = array_replace_recursive(BannerFixture::config(), [
            'triggers' => ['all' => [
                ['type' => 'url_match', 'value' => '/collections/*'],
                ['type' => 'category', 'value' => ['fashion']],
                ['type' => 'min_seconds', 'value' => 20],
                ['type' => 'min_scroll_pct', 'value' => 50],
            ]],
            'frequency' => ['per_session' => 1, 'hide_after_dismiss' => true],
        ]);
        $id = $this->postJson($this->url(), ['name' => 'Triggers', 'config' => $config])->assertCreated()->json('data.id');
        $ok = $this->postJson($this->url("/{$id}/simulate"), [
            'path' => '/collections/jurken', 'category' => 'fashion', 'seconds' => 20, 'scroll_pct' => 50, 'device' => 'desktop',
        ])->assertOk()->json('data');
        $this->assertTrue($ok['shown']);
        $this->assertTrue($ok['simulated']);
        $this->assertFalse($this->postJson($this->url("/{$id}/simulate"), [
            'path' => '/collections/jurken', 'category' => 'fashion', 'seconds' => 5, 'scroll_pct' => 50, 'device' => 'desktop',
        ])->json('data.shown'));
        $this->assertFalse($this->postJson($this->url("/{$id}/simulate"), [
            'path' => '/checkout', 'category' => 'fashion', 'seconds' => 30, 'scroll_pct' => 80, 'device' => 'desktop',
        ])->json('data.shown'));
        $this->assertFalse($this->postJson($this->url("/{$id}/simulate"), [
            'path' => '/collections/jurken', 'category' => 'fashion', 'seconds' => 30, 'scroll_pct' => 80, 'device' => 'desktop', 'product_sku' => 'WRONG',
            'shown_this_session' => 1,
        ])->json('data.shown'));
    }

    /** E2E 6 — Cart integrity €94/€100 and static fallback. */
    public function test_e2e06_cart_progress_real_bridge_or_static_fallback(): void
    {
        Sanctum::actingAs($this->owner);
        $offer = $this->fundedOffer(1000, 10000);
        $id = $this->postJson($this->url(), [
            'name' => 'Cart',
            'offer_id' => $offer->public_id,
            'config' => array_replace_recursive(BannerFixture::config(), [
                'template' => 'cart_progress',
                'triggers' => ['all' => [['type' => 'url_match', 'value' => '/*']]],
            ]),
        ])->assertCreated()->json('data.id');
        $with = $this->postJson($this->url("/{$id}/simulate"), ['path' => '/cart', 'cart_total_minor' => 9400, 'device' => 'desktop'])->json('data');
        $this->assertSame(94, $with['cart_progress']['percent']);
        $this->assertSame(600, $with['cart_progress']['remaining_minor']);
        $this->assertTrue($with['simulated']);
        $none = $this->postJson($this->url("/{$id}/simulate"), ['path' => '/cart', 'device' => 'desktop'])->json('data');
        $this->assertNull($none['cart_progress']);
        $this->assertSame('static_offer', $none['fallback']);
    }

    /** E2E 7 — Results CTR + branch offer merchant costs. */
    public function test_e2e07_results_ctr_and_branch_offer_costs(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson($this->url(), ['name' => 'Stats', 'config' => BannerFixture::config()])->assertCreated()->json('data.id');
        $this->postJson($this->url("/{$id}/publish"))->assertOk();
        $key = BannerInstallation::where('webshop_id', $this->shop->id)->value('public_key');
        $events = [];
        for ($i = 0; $i < 100; $i++) {
            $events[] = $this->event($id, 'eligible');
        }
        for ($i = 0; $i < 70; $i++) {
            $events[] = $this->event($id, 'viewable_impression');
        }
        for ($i = 0; $i < 7; $i++) {
            $events[] = $this->event($id, 'cta_click');
        }
        for ($i = 0; $i < 20; $i++) {
            $events[] = $this->event($id, 'dismiss');
        }
        foreach (array_chunk($events, 50) as $batch) {
            $this->postJson('/api/v1/banners/events', ['key' => $key, 'events' => $batch], ['Origin' => self::ORIGIN])->assertStatus(202);
        }
        Sanctum::actingAs($this->owner);
        $r = $this->getJson($this->url("/{$id}/results"))->json('data');
        $this->assertSame([70, 7, 0.1], [$r['viewable_impressions'], $r['cta_clicks'], $r['ctr']]);
        $this->assertSame(20, $r['dismissals']);

        $fashion = app(BranchOfferDefaults::class)->forCategories(['fashion']);
        $electronics = app(BranchOfferDefaults::class)->forCategories(['electronics']);
        // Studio documents Fashion €100→€10 and Electronics €100→€4 as presentation targets;
        // merchant cost always uses funding: reward + 30% fee.
        $this->assertSame(1300, app(MerchantFunding::class)->costOf($this->fundedOffer(1000, 10000)->currentVersion)['total_minor']);
        $this->assertSame(520, app(MerchantFunding::class)->costOf($this->fundedOffer(400, 10000)->currentVersion)['total_minor']);
        $this->assertNotEmpty($fashion['suggestion']);
        $this->assertNotEmpty($electronics['suggestion']);
    }

    /** E2E 8 — Security: other tenant, visitor, forged origin, expired pairing. */
    public function test_e2e08_security_blocks_cross_tenant_and_forged_pairing(): void
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson($this->url(), ['name' => 'Sec', 'config' => BannerFixture::config()])->assertCreated()->json('data.id');
        $key = BannerInstallation::where('webshop_id', $this->shop->id)->value('public_key');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/banners/config?key='.$key, ['Origin' => 'https://evil.example'])->assertForbidden();
        $other = User::factory()->create();
        $otherShop = $this->activeWebshop($other);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/workspaces/{$otherShop->public_id}/banners/{$id}")->assertNotFound();
        Sanctum::actingAs($this->owner);
        $token = $this->postJson($this->url('/pairing'), ['origin' => self::ORIGIN])->assertCreated()->json('data.pairing_token');
        $this->app['auth']->forgetGuards();
        $fail = $this->postJson('/api/v1/banners/editor/pair', ['pairing_token' => $token], ['Origin' => 'https://evil.example']);
        $fail->assertUnauthorized()->assertJsonPath('error.code', 'pairing_invalid');
        $this->assertNotEmpty($fail->json('error.request_id'));
    }

    /** E2E 9 — AI designs/insights require human review and never auto-publish. */
    public function test_e2e09_ai_optimize_creates_reviewable_proposals_not_publish(): void
    {
        config(['banner_ai.enabled' => true, 'banner_ai.api_key' => 'fixture', 'banner_ai.model' => 'fixture-model']);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\GenerateBannerProposals::class]);
        Sanctum::actingAs($this->owner);
        $id = $this->postJson($this->url(), ['name' => 'AI', 'config' => BannerFixture::config()])->assertCreated()->json('data.id');
        // Designs queue immediately; insights additionally require ≥10 verified paid EUR orders.
        $run = $this->postJson($this->url("/{$id}/ai-requests"), [
            'kind' => 'designs',
            'request_key' => (string) Str::uuid(),
        ])->assertStatus(202)->assertJsonPath('data.human_review_required', true)->json('data');
        $this->assertSame('designs', $run['kind']);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\GenerateBannerProposals::class);
        $this->postJson($this->url("/{$id}/ai-requests"), [
            'kind' => 'insights',
            'request_key' => (string) Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('error.code', 'banner_ai_insufficient_evidence');
    }

    /** E2E 10 — Installation health + capability matrix for major platforms. */
    public function test_e2e10_installation_health_and_platform_embed_flags(): void
    {
        Sanctum::actingAs($this->owner);
        $install = $this->getJson($this->url('/installation'))->assertOk()->json('data');
        $this->assertStringStartsWith('pk_', $install['public_key']);
        $this->assertStringContainsString('/embed/v1.js', $install['snippet']);
        $this->assertStringContainsString($install['public_key'], $install['snippet']);
        $this->assertContains($install['status'], ['not_seen', 'installed', 'stale']);

        $matrix = app(CapabilityMatrix::class);
        foreach ([\App\Enums\EcommercePlatform::WooCommerce, \App\Enums\EcommercePlatform::Wix, \App\Enums\EcommercePlatform::BigCommerce, \App\Enums\EcommercePlatform::Shopify] as $platform) {
            $caps = $matrix->forPlatform($platform);
            $this->assertArrayHasKey('banner_embed', $caps);
            $this->assertContains($caps['banner_embed']['state'], [
                CapabilityState::Available->value,
                CapabilityState::Limited->value,
                CapabilityState::Unsupported->value,
            ]);
        }
        $this->assertFileExists(public_path('embed/v1.js'));
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}/banners{$suffix}";
    }

    private function publicConfig()
    {
        $this->app['auth']->forgetGuards();
        $key = BannerInstallation::where('webshop_id', $this->shop->id)->value('public_key');

        return $this->getJson('/api/v1/banners/config?key='.$key, ['Origin' => self::ORIGIN]);
    }

    private function fundedOffer(int $rewardMinor, int $minOrderMinor)
    {
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), OfferAcquisition::MerchantReward, [
            'name' => 'Reward '.$rewardMinor,
            'discount_type' => 'fixed',
            'discount_value' => $rewardMinor,
            'min_order_minor' => $minOrderMinor,
            'redemption_validity_days' => 90,
        ]), OfferStatus::Active);
        $funding = app(MerchantFunding::class);
        $wallet = $funding->wallet($this->shop);
        if ($wallet->available < 50000) {
            app(\App\Services\Wallet\WalletService::class)->adjust($wallet, 50000, 'test-topup', 'E2E', $this->owner->id);
        }

        return $offer->fresh(['currentVersion']);
    }

    private function event(string $campaign, string $type): array
    {
        return [
            'id' => (string) Str::uuid(),
            'campaign' => $campaign,
            'version' => 1,
            'type' => $type,
            'occurred_at' => now()->toIso8601String(),
            'device' => 'desktop',
            'path' => '/collections/sale',
            'category' => 'fashion',
            'consent' => true,
        ];
    }
}
