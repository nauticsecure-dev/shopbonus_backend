<?php

namespace Tests\Feature\Banners;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Integrations\Connectors\GenericSignedWebhookConnector;
use App\Models\AuditEvent;
use App\Models\BannerCampaign;
use App\Models\BannerInstallation;
use App\Models\Offer;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Funding\MerchantFunding;
use App\Services\Offers\OfferService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B14: banner campaigns backend (Trello #25 E2E 3–8, #24 E2E 1–3).
 */
class BannerTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private const ORIGIN = 'https://www.woonenco.nl';

    private User $owner;

    private Webshop $shop;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co', 'verified_domain' => 'woonenco.nl']);
        $offers = app(OfferService::class);
        $this->offer = $offers->transition($offers->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), OfferAcquisition::MerchantReward,
            ['name' => '€10 voor je volgende aankoop', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 10000, 'redemption_validity_days' => 90]), OfferStatus::Active);
    }

    private function config(array $overrides = []): array
    {
        $triggers = $overrides['triggers'] ?? null;
        unset($overrides['triggers']);
        $config = array_replace_recursive([
            'template' => 'ribbon',
            'placement' => ['anchor' => 'top'],
            'content' => ['heading' => 'Besteed €100, krijg €10', 'subtitle' => 'Voor je volgende aankoop', 'cta_label' => 'Meer info', 'cta_url' => 'https://www.woonenco.nl/shopbonus', 'terms' => 'Min. €100'],
            'style' => ['background' => '#1D4ED8', 'text' => '#FFFFFF', 'font' => 'inter', 'animation' => 'fade_in'],
            'devices' => ['desktop', 'mobile'],
            'frequency' => ['per_session' => 1, 'cooldown_hours' => 0, 'hide_after_dismiss' => true],
            'triggers' => ['all' => [['type' => 'url_match', 'value' => '/collections/*'], ['type' => 'category', 'value' => ['fashion']], ['type' => 'min_seconds', 'value' => 20]]],
        ], $overrides);

        return $triggers === null ? $config : ['triggers' => $triggers] + $config;
    }

    private function url(string $s = ''): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}/banners{$s}";
    }

    private function create(array $config = []): string
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson($this->url(), ['name' => 'Zomer', 'offer_id' => $this->offer->public_id, 'config' => $this->config($config)])->assertCreated()
            ->assertJsonPath('data.draft_revision', 1)->json('data.id');
    }

    private function publicConfig(): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $key = BannerInstallation::where('webshop_id', $this->shop->id)->value('public_key');

        return $this->getJson('/api/v1/banners/config?key='.$key, ['Origin' => self::ORIGIN]);
    }

    private function events(array $events): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $key = BannerInstallation::where('webshop_id', $this->shop->id)->value('public_key');

        return $this->postJson('/api/v1/banners/events', ['key' => $key, 'events' => $events], ['Origin' => self::ORIGIN]);
    }

    private function event(string $campaign, string $type, bool $consent = true, string $device = 'desktop'): array
    {
        return ['id' => (string) Str::uuid(), 'campaign' => $campaign, 'version' => 1, 'type' => $type, 'occurred_at' => now()->toIso8601String(), 'device' => $device,
            'path' => '/collections/sale?utm=x', 'category' => 'fashion', 'consent' => $consent];
    }

    public function test_card25_e2e3_drafts_stay_private_until_published(): void
    {
        $id = $this->create();
        $this->getJson($this->url('/installation'))->assertOk()->assertJsonPath('data.allowed_origins', ['https://woonenco.nl', 'https://www.woonenco.nl'])->assertJsonPath('data.status', 'not_seen');
        $this->assertSame([], $this->publicConfig()->assertOk()->json('data.campaigns'));

        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/{$id}/publish"))->assertOk()->assertJsonPath('data.status', 'live')->assertJsonPath('data.live_version', 1);
        $this->putJson($this->url("/{$id}"), ['revision' => 1, 'config' => $this->config(['content' => ['heading' => 'Nieuwe tekst (concept)']])])->assertOk()->assertJsonPath('data.draft_revision', 2);
        $this->getJson($this->url("/{$id}"))->assertJsonPath('data.draft_config.content.heading', 'Nieuwe tekst (concept)')->assertJsonPath('data.published_config.content.heading', 'Besteed €100, krijg €10');

        $public = $this->publicConfig()->assertOk()->assertHeader('ETag');
        $this->assertSame('Besteed €100, krijg €10', $public->json('data.campaigns.0.config.content.heading')); // visitors still see v1
        $this->assertStringNotContainsString('concept', $public->getContent());
        $this->getJson('/api/v1/banners/config?key='.BannerInstallation::sole()->public_key, ['Origin' => self::ORIGIN, 'If-None-Match' => $public->headers->get('ETag')])->assertStatus(304);
        $this->assertSame(self::ORIGIN, BannerInstallation::sole()->last_seen_origin);

        // Stale editor: optimistic lock.
        Sanctum::actingAs($this->owner);
        $this->putJson($this->url("/{$id}"), ['revision' => 1, 'config' => $this->config()])->assertStatus(409)->assertJsonPath('error.code', 'draft_revision_conflict');
    }

    public function test_card25_e2e4_schedule_publish_pause_rollback_with_audit(): void
    {
        $id = $this->create();
        $this->postJson($this->url("/{$id}/schedule"), ['publish_at' => now('Europe/Amsterdam')->subHour()->format('Y-m-d\TH:i')])->assertUnprocessable();
        $this->postJson($this->url("/{$id}/schedule"), ['publish_at' => now('Europe/Amsterdam')->addHour()->format('Y-m-d\TH:i')])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->artisan('shopbonus:publish-scheduled-banners')->expectsOutputToContain('Published 0')->assertSuccessful();
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:publish-scheduled-banners')->expectsOutputToContain('Published 1')->assertSuccessful();
        $this->assertSame('live', BannerCampaign::sole()->status);

        Sanctum::actingAs($this->owner);
        $this->putJson($this->url("/{$id}"), ['revision' => 1, 'config' => $this->config(['content' => ['heading' => 'Versie 2']])])->assertOk();
        $this->postJson($this->url("/{$id}/publish"))->assertOk()->assertJsonPath('data.live_version', 2);
        $this->postJson($this->url("/{$id}/pause"))->assertOk()->assertJsonPath('data.status', 'paused');
        $this->assertSame([], $this->publicConfig()->json('data.campaigns'));
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/{$id}/pause"))->assertStatus(409);
        $this->postJson($this->url("/{$id}/resume"))->assertOk();
        $this->postJson($this->url("/{$id}/rollback"), ['version' => 1])->assertOk()->assertJsonPath('data.live_version', 3);
        $this->assertSame('Besteed €100, krijg €10', $this->publicConfig()->json('data.campaigns.0.config.content.heading'));

        Sanctum::actingAs($this->owner);
        $this->assertSame([3, 2, 1], array_column($this->getJson($this->url("/{$id}/versions"))->json('data'), 'version'));
        $this->assertSame(['banner.created', 'banner.scheduled', 'banner.published', 'banner.draft_saved', 'banner.published', 'banner.paused', 'banner.resumed', 'banner.rolled_back'],
            AuditEvent::where('action', 'like', 'banner.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_card25_e2e5_trigger_library_explains_why_shown_or_hidden(): void
    {
        $id = $this->create();
        $sim = fn (array $ctx) => $this->postJson($this->url("/{$id}/simulate"), $ctx + ['path' => '/collections/jurken', 'category' => 'fashion', 'seconds' => 25, 'device' => 'desktop'])->assertOk()->json('data');

        $shown = $sim([]);
        $this->assertTrue($shown['shown']);
        $this->assertTrue($shown['simulated']);
        $this->assertContains('matched: url_match /collections/*', $shown['reasons']);

        $this->assertFalse($sim(['seconds' => 5])['shown']);
        $this->assertContains('not matched: min_seconds 20', $sim(['seconds' => 5])['reasons']);
        $this->assertFalse($sim(['category' => 'electronics'])['shown']);
        $this->assertSame(['hidden: checkout, payment and account pages never show banners'], $sim(['path' => '/checkout'])['reasons']);
        $this->assertSame(['hidden: already shown 1× this session'], $sim(['shown_this_session' => 1])['reasons']);
        $this->assertSame(['hidden: the visitor closed it before'], $sim(['dismissed' => true])['reasons']);
        $this->assertSame(['hidden: not shown on tablet'], $sim(['device' => 'tablet'])['reasons']);

        // AND/OR: show on product SKU A1 OR (returning visitor AND scroll ≥ 50%).
        Sanctum::actingAs($this->owner);
        $this->putJson($this->url("/{$id}"), ['revision' => 1, 'config' => array_replace($this->config(), ['triggers' => ['any' => [
            ['type' => 'product_sku', 'value' => ['A1']], ['all' => [['type' => 'visitor', 'value' => 'returning'], ['type' => 'min_scroll_pct', 'value' => 50]]],
        ]]])])->assertOk();
        $this->assertTrue($sim(['product_sku' => 'A1'])['shown']);
        $this->assertFalse($sim(['product_sku' => 'B2', 'visitor' => 'returning', 'scroll_pct' => 10])['shown']);
        $this->assertTrue($sim(['product_sku' => 'B2', 'visitor' => 'returning', 'scroll_pct' => 60])['shown']);
    }

    public function test_card25_e2e6_cart_progress_needs_a_verified_cart_or_falls_back(): void
    {
        $id = $this->create(['template' => 'cart_progress', 'triggers' => ['all' => [['type' => 'url_match', 'value' => '/*']]]]);
        $sim = fn (array $ctx) => $this->postJson($this->url("/{$id}/simulate"), $ctx + ['path' => '/cart'])->json('data');

        $this->assertSame(['cart_total_minor' => 9400, 'threshold_minor' => 10000, 'remaining_minor' => 600, 'percent' => 94], $sim(['cart_total_minor' => 9400])['cart_progress']);
        $none = $sim([]);
        $this->assertSame([null, 'static_offer'], [$none['cart_progress'], $none['fallback']]);
    }

    public function test_card25_e2e7_and_card24_e2e1_to_3_measurement_attribution_and_cost(): void
    {
        $id = $this->create();
        $this->postJson($this->url("/{$id}/publish"))->assertOk();

        $events = [];
        for ($i = 0; $i < 100; $i++) {
            $events[] = $this->event($id, 'eligible');
        }
        for ($i = 0; $i < 70; $i++) {
            $events[] = $this->event($id, 'viewable_impression', device: $i < 50 ? 'desktop' : 'mobile');
        }
        for ($i = 0; $i < 20; $i++) {
            $events[] = $this->event($id, 'dismiss');
        }
        $clicks = [];
        for ($i = 0; $i < 7; $i++) {
            $clicks[] = $this->event($id, 'cta_click', consent: $i < 6);
        }
        foreach (array_chunk([...$events, ...$clicks], 50) as $batch) {
            $this->events($batch)->assertStatus(202);
        }
        $retry = $this->events(array_slice($clicks, 0, 3))->assertStatus(202)->json('data');
        $this->assertSame([0, 3], [$retry['accepted'], $retry['duplicates']]);

        Sanctum::actingAs($this->owner);
        $r = $this->getJson($this->url("/{$id}/results"))->assertOk()->json('data');
        $this->assertSame([100, 70, 7, 20, 0.1], [$r['eligible'], $r['viewable_impressions'], $r['cta_clicks'], $r['dismissals'], $r['ctr']]);
        $this->assertSame(20, $this->getJson($this->url("/{$id}/results?device=mobile"))->json('data.viewable_impressions'));
        $this->assertSame('/collections/sale', \DB::table('banner_events')->value('path')); // no query string stored

        // Consented clicks get a click ID; the non-consented one does not.
        $clickIds = \DB::table('banner_clicks')->pluck('click_id');
        $this->assertCount(6, $clickIds);

        // Two clicks, one paid €112 order carrying the click ID, duplicate webhook → one attributed order.
        $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $connection = WebshopConnection::sole();
        WebshopOrder::create(['webshop_id' => $this->shop->id, 'connection_id' => $connection->id, 'platform' => 'prestashop', 'external_order_id' => 'X1', 'reference' => 'X1',
            'ingestion_status' => 'complete', 'currency' => 'EUR', 'amount_minor' => 11200, 'payment_status' => 'paid', 'paid_at' => now(), 'placed_at' => now(), 'attribution_click_id' => $clickIds[0]]);
        WebshopOrder::create(['webshop_id' => $this->shop->id, 'connection_id' => $connection->id, 'platform' => 'prestashop', 'external_order_id' => 'X2', 'reference' => 'X2',
            'ingestion_status' => 'complete', 'currency' => 'EUR', 'amount_minor' => 5000, 'payment_status' => 'pending', 'placed_at' => now(), 'attribution_click_id' => $clickIds[1]]);
        $r = $this->getJson($this->url("/{$id}/results"))->json('data');
        $this->assertSame([1, 11200], [$r['attributed_orders'], $r['attributed_revenue_minor']]);

        // Merchant overview now carries the banner totals.
        $this->assertSame(0.1, $this->getJson("/api/v1/workspaces/{$this->shop->public_id}/analytics/overview")->json('data.banner_metrics.ctr'));
        // Cost of the linked reward: €10 + €3 fee.
        $this->assertSame(1300, app(MerchantFunding::class)->costOf($this->offer->currentVersion)['total_minor']);
    }

    public function test_click_ids_travel_through_the_shopbonus_order_format(): void
    {
        $id = $this->create();
        $this->postJson($this->url("/{$id}/publish"))->assertOk();
        $clickId = $this->events([$this->event($id, 'cta_click')])->json('data.click_ids.*')[0];
        $this->assertMatchesRegularExpression('/^bc_[A-Za-z0-9]{24}$/', $clickId);

        $o = app(GenericSignedWebhookConnector::class)->normalizeOrder(['order' => ['id' => '1', 'reference' => 'R', 'currency' => 'EUR', 'total_minor' => 100,
            'customer' => ['first_name' => 'A', 'email' => 'a@example.nl'], 'payment_status' => 'paid', 'created_at' => now()->toIso8601String(), 'attribution' => ['click_id' => $clickId]]]);
        $this->assertSame($clickId, $o->attributionClickId);
        $bad = app(GenericSignedWebhookConnector::class)->normalizeOrder(['order' => ['id' => '1', 'reference' => 'R', 'currency' => 'EUR', 'total_minor' => 100,
            'customer' => ['first_name' => 'A', 'email' => 'a@example.nl'], 'payment_status' => 'paid', 'created_at' => now()->toIso8601String(), 'attribution' => ['click_id' => '<script>']]]);
        $this->assertNull($bad->attributionClickId);
    }

    public function test_schema_rejects_unsafe_content_and_publish_needs_funding(): void
    {
        Sanctum::actingAs($this->owner);
        foreach ([['content' => ['heading' => '<img src=x onerror=alert(1)>']], ['content' => ['cta_url' => 'http://woonenco.nl']], ['content' => ['cta_url' => 'javascript:alert(1)']],
            ['style' => ['background' => 'red']], ['template' => 'iframe'], ['triggers' => ['all' => [['type' => 'eval', 'value' => 'x']]]], ['content' => ['image_asset_id' => '01jxxxxxxxxxxxxxxxxxxxxxxx']]] as $bad) {
            $this->postJson($this->url(), ['name' => 'X', 'config' => $this->config($bad)])->assertUnprocessable();
        }

        $id = $this->create();
        $wallet = app(MerchantFunding::class)->wallet($this->shop);
        app(WalletService::class)->adjust($wallet, -$wallet->available, 'drain', 'Test drain', $this->owner->id);
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/{$id}/publish"))->assertStatus(409)->assertJsonPath('error.code', 'funding_insufficient')->assertJsonPath('error.details.required_minor', 1300);
    }

    public function test_card25_e2e8_other_tenants_visitors_forged_origins_and_stale_pairings_are_refused(): void
    {
        $id = $this->create();
        $key = BannerInstallation::sole()->public_key;

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/banners/config?key='.$key, ['Origin' => 'https://evil.example'])->assertForbidden()->assertJsonPath('error.code', 'origin_not_allowed');
        $this->postJson('/api/v1/banners/events', ['key' => $key, 'events' => [$this->event($id, 'cta_click')]], ['Origin' => 'https://evil.example'])->assertForbidden();
        $this->getJson("/api/v1/banners/editor/campaigns/{$id}", ['Origin' => self::ORIGIN])->assertUnauthorized();

        $other = User::factory()->create();
        $otherShop = $this->activeWebshop($other);
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/workspaces/{$otherShop->public_id}/banners/{$id}")->assertNotFound();
        $this->getJson($this->url("/{$id}"))->assertNotFound();

        // Pairing: single use, origin bound, expiring; the editor token reaches drafts of this shop only.
        Sanctum::actingAs($this->owner);
        $this->postJson($this->url('/pairing'), ['origin' => 'https://evil.example'])->assertUnprocessable();
        $token = $this->postJson($this->url('/pairing'), ['origin' => self::ORIGIN])->assertCreated()->json('data.pairing_token');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/banners/editor/pair', ['pairing_token' => $token], ['Origin' => 'https://evil.example'])->assertUnauthorized();
        $editor = $this->postJson('/api/v1/banners/editor/pair', ['pairing_token' => $token], ['Origin' => self::ORIGIN])->assertOk()->json('data.editor_token');
        $this->postJson('/api/v1/banners/editor/pair', ['pairing_token' => $token], ['Origin' => self::ORIGIN])->assertUnauthorized(); // replay

        $h = ['Origin' => self::ORIGIN, 'X-Banner-Editor-Token' => $editor];
        $this->getJson("/api/v1/banners/editor/campaigns/{$id}", $h)->assertOk()->assertJsonPath('data.draft_revision', 1);
        $this->putJson("/api/v1/banners/editor/campaigns/{$id}", ['revision' => 1, 'config' => $this->config(['content' => ['heading' => 'Via de editor']])], $h)->assertOk();
        $this->getJson("/api/v1/banners/editor/campaigns/{$id}", ['Origin' => 'https://evil.example', 'X-Banner-Editor-Token' => $editor])->assertUnauthorized();
        $this->travel(31)->minutes();
        $this->getJson("/api/v1/banners/editor/campaigns/{$id}", $h)->assertUnauthorized();
    }
}
