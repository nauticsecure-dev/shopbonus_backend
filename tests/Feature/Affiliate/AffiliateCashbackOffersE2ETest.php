<?php

namespace Tests\Feature\Affiliate;

use App\Console\Commands\EnrichMerchants;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Models\AffiliateClick;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Services\Affiliate\AffiliateSync;
use App\Services\Affiliate\ProgramSelector;
use App\Services\Enrichment\EnrichmentPipeline;
use App\Services\Enrichment\SafeUrlFetcher;
use App\Services\Offers\OfferService;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\TracksWithMarketingConsent;
use Tests\Fakes\FakeHostResolver;
use Tests\TestCase;

/**
 * Affiliate cashback acceptance (discovery, wallet, security).
 *
 * Scenarios 03–04 (website enrichment + AI cover): see MerchantEnrichmentE2ETest.
 */
class AffiliateCashbackOffersE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;
    use TracksWithMarketingConsent;

    private User $admin;

    private User $anna;

    private Webshop $shop;

    private AffiliateConnection $daisycon;

    private AffiliateConnection $tradetracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->anna = User::factory()->create(['email' => 'anna@example.nl']);

        $owner = User::factory()->create();
        $this->shop = $this->activeWebshop($owner, ['name' => 'Fietsen Utrecht', 'verified_domain' => 'fietsen-utrecht.nl']);
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/workspaces/{$this->shop->public_id}/public-profile", ['short_description' => 'Fietsen', 'website_url' => 'https://fietsen-utrecht.nl', 'categories' => ['sports_outdoor'], 'published' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->daisycon = AffiliateConnection::create([
            'network' => 'daisycon', 'name' => 'Daisycon', 'status' => 'connected',
            'credentials' => ['publisher_id' => '123', 'access_token' => 'tok', 'media_id' => '9'],
            'postback_token_hash' => AffiliateConnection::hashToken('daisycon-token'),
        ]);
        $this->tradetracker = AffiliateConnection::create([
            'network' => 'tradetracker', 'name' => 'TradeTracker', 'status' => 'connected',
            'credentials' => ['customer_id' => '456', 'passphrase' => 'secret', 'site_id' => '7'],
            'postback_token_hash' => AffiliateConnection::hashToken('tradetracker-token'),
        ]);
        AffiliateRewardRule::create([
            'version' => 1, 'unit' => 'eur_credit', 'share_bps' => 7000, 'min_commission_minor' => 0,
            'review_above_minor' => 50000, 'active' => true, 'created_by' => $this->admin->id,
        ]);
    }

    /** @param array<string, mixed> $commission */
    private function importPrograms(array $commission = ['type' => 'percentage', 'value' => '10', 'restricted' => true], bool $cashbackAllowed = true): void
    {
        $program = [
            'id' => 'P1', 'name' => 'Fietsen Utrecht', 'url' => 'https://www.fietsen-utrecht.nl', 'status' => 'active', 'currency_code' => 'EUR',
            'commission' => $commission, 'cashback_allowed' => $cashbackAllowed, 'tracking_url' => 'https://lt45.net/c/?si=1&li=2&wi=3',
        ];
        app(AffiliateSync::class)->import($this->daisycon, [$program], [], $this->admin->id);
    }

    /** @return array<string, mixed> */
    private function tradetrackerProgram(): array
    {
        return ['ID' => 'T1', 'name' => 'Fietsen Utrecht', 'URL' => 'https://fietsen-utrecht.nl', 'info' => ['assignmentStatus' => 'accepted', 'policyCashbackStatus' => true,
            'commission' => ['saleCommissionVariable' => '8', 'categoryRestricted' => false], 'trackingURL' => 'https://tc.tradetracker.net/?c=1&m=2&a=3']];
    }

    private function promoOffer(bool $combinable, int $discountBps = 1000, string $name = '10% kortingscode'): void
    {
        $membership = $this->shop->memberships()->where('role', 'owner')->firstOrFail()->setRelation('webshop', $this->shop);
        $offer = app(OfferService::class)->transition(app(OfferService::class)->create($membership, OfferAcquisition::PromotionalCode, [
            'name' => $name, 'discount_type' => 'percentage', 'discount_value' => $discountBps, 'combinable' => $combinable,
            'redemption_validity_days' => 30, 'code_mode' => 'reusable',
        ]), OfferStatus::Active);
        VoucherCode::create([
            'webshop_id' => $this->shop->id, 'offer_id' => $offer->id, 'code' => 'SAVE10NOW',
            'code_hash' => VoucherCode::hashFor($this->shop->id, 'SAVE10NOW'), 'code_last4' => '0NOW',
        ]);
    }

    public function test_acceptance_01_daisycon_import_maps_ten_percent_to_seven_consumer_three_platform(): void
    {
        $this->importPrograms(['type' => 'percentage', 'value' => '10', 'restricted' => true]);
        $program = AffiliateProgram::where('external_id', 'P1')->sole();
        $this->assertSame(1000, $program->commission_value);
        $this->assertTrue($program->category_restricted);

        $this->app['auth']->forgetGuards();
        $detail = $this->getJson('/api/v1/public/webshops/fietsen-utrecht')->assertOk();
        $this->assertSame('Tot 7% cashback', $detail->json('data.cashback_label'));
        $this->assertSame(0.07, $detail->json('data.benefits.0.consumer_cashback_rate'));
    }

    public function test_acceptance_02_tradetracker_import_maps_program_to_verified_webshop(): void
    {
        app(AffiliateSync::class)->import($this->tradetracker, [$this->tradetrackerProgram()], [], $this->admin->id);
        $program = AffiliateProgram::where('external_id', 'T1')->sole();
        $this->assertSame($this->shop->id, $program->webshop_id);
        $this->assertSame(800, $program->commission_value);
        $this->assertStringContainsString('tradetracker.net', $program->tracking_url_template);
    }

    public function test_acceptance_03_website_enrichment_command_and_pipeline_exist(): void
    {
        $this->assertTrue(class_exists(EnrichmentPipeline::class));
        $this->assertTrue(class_exists(EnrichMerchants::class));
        config(['enrichment.enabled' => false]);
        $this->assertSame(0, Artisan::call('shopbonus:enrich-merchants', ['--limit' => 1]));
    }

    public function test_acceptance_04_ai_image_cover_is_covered_by_merchant_enrichment_e2e(): void
    {
        $this->markTestSkipped('Cover/image path: MerchantEnrichmentE2ETest::test_image_failure_falls_back_without_failing_enrichment');
    }

    public function test_acceptance_05_cashback_page_shows_seven_percent_without_prepaid_unlock_fields(): void
    {
        $this->importPrograms(['type' => 'percentage', 'value' => '10', 'restricted' => false]);
        $this->app['auth']->forgetGuards();

        $detail = $this->getJson('/api/v1/public/webshops/fietsen-utrecht')->assertOk();
        $this->assertSame('7% cashback', $detail->json('data.cashback_label'));

        $cashbackCard = collect($detail->json('data.offers'))->firstWhere('offer_kind', 'cashback');
        $this->assertNotNull($cashbackCard);
        $this->assertNull($cashbackCard['slug']);
        $this->assertNull($cashbackCard['price_minor']);
        $this->assertSame('earn_cashback', $cashbackCard['how_to_obtain']);
        $this->assertSame('shop_and_earn', $cashbackCard['cta_hint']);
    }

    public function test_acceptance_06_discount_code_shows_fifteen_percent_promo_label(): void
    {
        $this->importPrograms(['type' => 'percentage', 'value' => '10', 'restricted' => false]);
        $this->promoOffer(combinable: false, discountBps: 1500, name: '15% kortingscode');
        $this->app['auth']->forgetGuards();

        $offer = collect($this->getJson('/api/v1/public/webshops/fietsen-utrecht')->json('data.offers'))
            ->firstWhere('title', '15% kortingscode');
        $this->assertSame('15% korting', $offer['benefit_label']);
        $this->assertSame('discount_code', $offer['offer_kind']);
    }

    public function test_acceptance_07_combined_when_combinable_true_blocks_when_false(): void
    {
        $this->importPrograms();
        $this->promoOffer(combinable: true);
        $this->app['auth']->forgetGuards();

        $combined = collect($this->getJson('/api/v1/public/webshops/fietsen-utrecht')->json('data.offers'))
            ->firstWhere('title', '10% kortingscode');
        $this->assertSame('combined', $combined['offer_kind']);
        $this->assertSame('claim_both', $combined['cta_hint']);

        $owner = User::factory()->create();
        $shop2 = $this->activeWebshop($owner, ['name' => 'Fietsen Den Bosch', 'verified_domain' => 'fietsen-den-bosch.nl']);
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/workspaces/{$shop2->public_id}/public-profile", ['short_description' => 'Fietsen', 'website_url' => 'https://fietsen-den-bosch.nl', 'categories' => ['sports_outdoor'], 'published' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        app(AffiliateSync::class)->import($this->daisycon, [[
            'id' => 'P2', 'name' => 'Fietsen Den Bosch', 'url' => 'https://fietsen-den-bosch.nl', 'status' => 'active', 'currency_code' => 'EUR',
            'commission' => ['type' => 'percentage', 'value' => '10', 'restricted' => false], 'cashback_allowed' => true,
            'tracking_url' => 'https://lt45.net/c/?si=1&li=2&wi=4',
        ]], [], $this->admin->id);

        $membership = $shop2->memberships()->where('role', 'owner')->firstOrFail()->setRelation('webshop', $shop2);
        app(OfferService::class)->transition(app(OfferService::class)->create($membership, OfferAcquisition::PromotionalCode, [
            'name' => 'Niet combineerbaar', 'discount_type' => 'percentage', 'discount_value' => 1000, 'combinable' => false,
            'redemption_validity_days' => 30, 'code_mode' => 'reusable',
        ]), OfferStatus::Active);

        $solo = collect($this->getJson('/api/v1/public/webshops/fietsen-den-bosch')->json('data.offers'))
            ->firstWhere('title', 'Niet combineerbaar');
        $this->assertSame('discount_code', $solo['offer_kind']);
    }

    public function test_acceptance_08_tracked_conversion_hundred_ten_yields_seven_pending_and_three_platform_evidence(): void
    {
        config(['shopbonus.affiliate.credit_timing' => 'on_network_approval']);
        $this->importPrograms(['type' => 'percentage', 'value' => '10', 'restricted' => false]);

        Sanctum::actingAs($this->anna);
        $this->get('/api/v1/public/webshops/fietsen-utrecht/visit'.$this->marketingConsentQuery());
        $click = AffiliateClick::sole();
        $this->app['auth']->forgetGuards();

        $txRow = [
            'affiliatemarketing_id' => 'D-100', 'program_id' => 'P1', 'date' => now()->toIso8601String(), 'last_modified' => now()->toIso8601String(),
            'parts' => [[
                'subid' => $click->reference, 'referencenumber' => 'ORD-100', 'revenue' => 100, 'commission' => 10,
                'status' => 'pending', 'currency_code' => 'EUR',
            ]],
        ];
        app(AffiliateSync::class)->import($this->daisycon, [], [$txRow], $this->admin->id);
        app(AffiliateSync::class)->import($this->daisycon, [], [$txRow], $this->admin->id);

        $tx = AffiliateTransaction::where('external_id', 'D-100')->sole();
        $this->assertSame([10000, 1000, 700], [$tx->order_amount_minor, $tx->commission_minor, $tx->reward_amount]);
        $this->assertSame(300, $tx->commission_minor - $tx->reward_amount);

        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/me/wallet')->assertOk()
            ->assertJsonPath('data.pending_cashback_minor', 700)
            ->assertJsonPath('data.available_minor', 0);
        $this->getJson('/api/v1/consumer/wallet/transactions?filter=pending')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.amount_minor', 700);
    }

    public function test_acceptance_09_settlement_approve_then_reversal_via_affiliate_processor(): void
    {
        config(['shopbonus.affiliate.credit_timing' => 'on_network_approval']);
        $this->importPrograms();
        Sanctum::actingAs($this->anna);
        $this->get('/api/v1/public/webshops/fietsen-utrecht/visit'.$this->marketingConsentQuery());
        $ref = AffiliateClick::sole()->reference;
        $this->app['auth']->forgetGuards();

        $base = [
            'affiliatemarketing_id' => 'D-SETTLE', 'program_id' => 'P1', 'date' => now()->toIso8601String(),
            'parts' => [['subid' => $ref, 'referencenumber' => 'ORD-S', 'revenue' => 100, 'commission' => 10, 'status' => 'pending', 'currency_code' => 'EUR']],
        ];
        app(AffiliateSync::class)->import($this->daisycon, [], [$base], $this->admin->id);
        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/me/wallet')->assertJsonPath('data.pending_cashback_minor', 700)->assertJsonPath('data.available_minor', 0);
        $this->app['auth']->forgetGuards();

        $approved = $base;
        $approved['last_modified'] = now()->addHour()->toIso8601String();
        $approved['parts'][0]['status'] = 'approved';
        app(AffiliateSync::class)->import($this->daisycon, [], [$approved], $this->admin->id);
        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/me/wallet')->assertJsonPath('data.pending_cashback_minor', 0)->assertJsonPath('data.available_minor', 700);
        $this->app['auth']->forgetGuards();

        $reversed = $approved;
        $reversed['last_modified'] = now()->addHours(2)->toIso8601String();
        $reversed['parts'][0]['status'] = 'reversed';
        app(AffiliateSync::class)->import($this->daisycon, [], [$reversed], $this->admin->id);

        $tx = AffiliateTransaction::where('external_id', 'D-SETTLE')->sole();
        $this->assertSame(['reversed', 'reversed'], [$tx->status, $tx->reward_status]);
        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/me/wallet')->assertJsonPath('data.available_minor', 0);
    }

    public function test_acceptance_10_security_malicious_url_blocked_cashback_denied_and_visit_attribution(): void
    {
        Http::fake();
        $dns = new FakeHostResolver;
        $dns->addresses['evil.example.nl'] = ['169.254.169.254'];
        $this->app->instance(HostResolver::class, $dns);
        $this->assertNull(app(SafeUrlFetcher::class)->fetch('https://evil.example.nl/', 4096));
        Http::assertNothingSent();

        $this->importPrograms(cashbackAllowed: false);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/webshops/fietsen-utrecht')->assertOk()
            ->assertJsonPath('data.cashback_label', null)
            ->assertJsonMissingPath('data.benefits.0.consumer_cashback_rate');

        AffiliateProgram::query()->update(['cashback_allowed' => true, 'active' => true]);
        $this->promoOffer(combinable: true);
        $offer = $this->shop->offers()->latest('id')->firstOrFail();
        DB::table('offer_versions')->where('id', $offer->current_version_id)->update(['ends_at' => now()->subDay()]);
        $offer->touch();
        $this->app['auth']->forgetGuards();
        $expired = collect($this->getJson('/api/v1/public/webshops/fietsen-utrecht')->json('data.offers'))
            ->firstWhere('title', '10% kortingscode');
        $this->assertSame('expired', $expired['availability']);

        config(['shopbonus.affiliate.credit_timing' => 'on_network_approval']);
        $this->importPrograms();
        app(ProgramSelector::class)->select($this->shop->fresh());
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/public/webshops/fietsen-utrecht/visit'.$this->marketingConsentQuery())->assertRedirect();
        $anonRef = AffiliateClick::latest('id')->firstOrFail()->reference;
        $this->assertNull(AffiliateClick::latest('id')->first()->user_id);

        Sanctum::actingAs($this->anna);
        $this->get('/api/v1/public/webshops/fietsen-utrecht/visit'.$this->marketingConsentQuery())->assertRedirect();
        $annaRef = AffiliateClick::where('user_id', $this->anna->id)->latest('id')->firstOrFail()->reference;
        $this->app['auth']->forgetGuards();

        $txRow = [
            'affiliatemarketing_id' => 'D-DUP', 'program_id' => 'P1', 'date' => now()->toIso8601String(),
            'parts' => [['subid' => $annaRef, 'referencenumber' => 'ORD-D', 'revenue' => 100, 'commission' => 10, 'status' => 'pending', 'currency_code' => 'EUR']],
        ];
        app(AffiliateSync::class)->import($this->daisycon, [], [$txRow], $this->admin->id);
        app(AffiliateSync::class)->import($this->daisycon, [], [$txRow], $this->admin->id);
        $this->assertSame(1, AffiliateTransaction::where('external_id', 'D-DUP')->count());

        $wrongUser = [
            'affiliatemarketing_id' => 'D-WRONG', 'program_id' => 'P1', 'date' => now()->toIso8601String(),
            'parts' => [['subid' => $anonRef, 'referencenumber' => 'ORD-W', 'revenue' => 50, 'commission' => 5, 'status' => 'pending', 'currency_code' => 'EUR']],
        ];
        app(AffiliateSync::class)->import($this->daisycon, [], [$wrongUser], $this->admin->id);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/me/wallet')->assertJsonPath('data.pending_cashback_minor', 0);
        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/me/wallet')->assertJsonPath('data.pending_cashback_minor', 700);
    }
}
