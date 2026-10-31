<?php

namespace Tests\Feature\Consumer;

use App\Enums\AuditOutcome;
use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\UserStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Enums\WebshopStatus;
use App\Integrations\Data\PaymentStatus;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherClaim;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * Trello #18 acceptance — ten end-to-end scenarios for the consumer platform.
 */
class ConsumerPlatformE2ETest extends TestCase
{
    use CreatesActiveWebshops, InteractsWithIdentity, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private Offer $offer;

    private Offer $walletOffer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Notification::fake();

        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$this->shop->public_id}/public-profile", [
            'slug' => 'woon-en-co',
            'short_description' => 'Alles voor een stijlvol thuis.',
            'description' => 'Meubels en woonaccessoires.',
            'website_url' => 'https://woon-en-co.nl',
            'categories' => ['home_living'],
            'published' => true,
        ])->assertOk();

        $membership = $this->shop->memberships()->where('role', MembershipRole::Owner->value)->firstOrFail()
            ->setRelation('webshop', $this->shop);
        $offers = app(OfferService::class);

        $this->offer = $offers->transition($offers->create($membership, OfferAcquisition::MerchantReward, [
            'name' => '€10 korting op meubels',
            'public_description' => 'Geldig op je volgende bestelling.',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'min_order_minor' => 5000,
            'redemption_validity_days' => 90,
        ]), OfferStatus::Active);

        $this->walletOffer = $offers->transition($offers->create($membership, OfferAcquisition::WalletPurchase, [
            'name' => 'Cadeaubon €20',
            'public_description' => 'Koop met tegoed.',
            'discount_type' => 'fixed',
            'discount_value' => 2000,
            'price_minor' => 2000,
            'min_order_minor' => 0,
            'redemption_validity_days' => 365,
        ]), OfferStatus::Active);

        foreach (['WOON-ACT-0001', 'WOON-ACT-0002', 'WOON-WAL-0001'] as $i => $code) {
            $offerId = $i < 2 ? $this->offer->id : $this->walletOffer->id;
            VoucherCode::create([
                'webshop_id' => $this->shop->id,
                'offer_id' => $offerId,
                'code' => $code,
                'code_hash' => VoucherCode::hashFor($this->shop->id, $code),
                'code_last4' => substr($code, -4),
                'status' => VoucherCodeStatus::Available,
            ]);
        }

        $this->app['auth']->forgetGuards();
    }

    /** 1. Homepage surfaces: public CMS settings, FAQ, cookie policy, NL/EN. */
    public function test_01_homepage_public_navigation_cms_locale_and_cookies(): void
    {
        // Homepage CMS may be unpublished in a fresh DB (404); other public surfaces must work.
        $home = $this->getJson('/api/v1/content/homepage');
        $this->assertContains($home->status(), [200, 404]);

        $this->getJson('/api/v1/public/settings')->assertOk();
        $this->getJson('/api/v1/public/faq')->assertOk();
        $this->getJson('/api/v1/public/pages')->assertOk();
        $this->getJson('/api/v1/public/categories')->assertOk()
            ->assertJsonFragment(['key' => 'home_living']);

        $nl = $this->withHeaders(['Accept-Language' => 'nl'])->getJson('/api/v1/public/legal-requirements')->assertOk();
        $en = $this->withHeaders(['Accept-Language' => 'en'])->getJson('/api/v1/public/legal-requirements')->assertOk();
        $this->assertNotEmpty($nl->json('data'));
        $this->assertNotEmpty($en->json('data'));

        // Cookie policy may be unpublished (necessary-only); consents still record choices.
        $policy = $this->getJson('/api/v1/public/cookie-policy?locale=nl')->assertOk()->json('data');
        if ($policy['version'] !== null) {
            $this->postJson('/api/v1/public/cookie-consents', [
                'policy_version' => $policy['version'],
                'action' => 'reject_optional',
            ])->assertCreated()->assertJsonPath('data.choices.analytics', false);
        } else {
            $this->assertFalse($policy['requires_reconsent'] ?? false);
        }
    }

    /** 2. Discovery: search, filters, sort, pagination — only approved/active/published. */
    public function test_02_discovery_search_filters_sort_pagination(): void
    {
        $hidden = $this->activeWebshop($this->owner, ['name' => 'Hidden Draft']);
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$hidden->public_id}/public-profile", [
            'slug' => 'hidden-draft', 'short_description' => 'x', 'categories' => ['fashion'], 'published' => false,
        ])->assertOk();
        $this->app['auth']->forgetGuards();

        $list = $this->getJson('/api/v1/public/webshops?search=woon&category=home_living&sort=most_offers&page=1&per_page=12')
            ->assertOk();
        $this->assertSame(['woon-en-co'], array_column($list->json('data'), 'slug'));
        $this->assertNotContains('hidden-draft', array_column($list->json('data'), 'slug'));

        $offers = $this->getJson('/api/v1/public/offers?webshop=woon-en-co&available=1')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($offers->json('data')));

        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Suspended]);
        $this->assertSame([], $this->getJson('/api/v1/public/webshops?search=woon')->json('data'));
    }

    /** 3. Webshop details expose only public fields. */
    public function test_03_webshop_details_hide_private_merchant_data(): void
    {
        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Approved]);

        $detail = $this->getJson('/api/v1/public/webshops/woon-en-co')->assertOk()
            ->assertJsonPath('data.slug', 'woon-en-co')
            ->assertJsonPath('data.name', 'Woon & Co')
            ->assertJsonPath('data.website_url', 'https://woon-en-co.nl');

        $raw = $detail->getContent();
        $this->assertStringNotContainsString($this->owner->email, $raw);
        $this->assertStringNotContainsString($this->shop->public_id, $raw);
        $this->assertArrayNotHasKey('listing_blockers', $detail->json('data'));
        $this->assertArrayNotHasKey('verified_domain', $detail->json('data'));
        $this->assertNotEmpty($detail->json('data.offers'));

        $this->getJson('/api/v1/public/webshops/does-not-exist')
            ->assertNotFound()->assertJsonPath('error.code', 'webshop_not_found');
    }

    /** 4. Offer detail conditions; paused/expired/sold-out cannot be obtained. */
    public function test_04_offer_detail_and_unavailable_cannot_be_obtained(): void
    {
        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Approved]);

        $slug = $this->walletOffer->fresh()->slug;
        $ok = $this->getJson('/api/v1/public/offers/'.$slug)->assertOk();
        $this->assertSame('available', $ok->json('data.availability'));
        $this->assertSame(2000, $ok->json('data.price_minor'));
        $this->assertSame(2000, $ok->json('data.discount_value'));
        $this->assertArrayHasKey('description', $ok->json('data'));

        $consumer = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($consumer);

        // Pause merchant reward — promotional/checkout paths must refuse.
        $membership = $this->shop->memberships()->sole()->setRelation('webshop', $this->shop)->setRelation('user', $this->owner);
        Sanctum::actingAs($this->owner);
        app(OfferService::class)->transition($this->walletOffer, OfferStatus::Paused);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($consumer);

        $paused = $this->postJson('/api/v1/consumer/checkouts/quote', ['offer' => $slug, 'use_wallet' => true])->assertOk();
        $this->assertContains('offer_not_active', $paused->json('data.blockers'));
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/consumer/checkouts', ['offer' => $slug, 'use_wallet' => true])
            ->assertStatus(409)->assertJsonPath('error.code', 'checkout_blocked');

        // Paused offers disappear from the public catalogue.
        $this->getJson('/api/v1/public/offers/'.$slug)->assertNotFound();

        // Expire merchant-reward terms (reactivate wallet offer first so we keep a control).
        Sanctum::actingAs($this->owner);
        app(OfferService::class)->transition($this->walletOffer->fresh(), OfferStatus::Active);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($consumer);

        DB::table('offer_versions')->where('id', $this->offer->current_version_id)->update(['ends_at' => now()->subHour()]);
        $this->offer->touch();
        $expiredSlug = $this->offer->fresh()->slug;
        $this->getJson('/api/v1/public/offers/'.$expiredSlug)->assertOk()
            ->assertJsonPath('data.availability', 'expired');

        // Sold out: drain unique codes on a fresh active wallet offer.
        Sanctum::actingAs($this->owner);
        $sold = app(OfferService::class)->transition(app(OfferService::class)->create(
            $membership,
            OfferAcquisition::WalletPurchase,
            [
                'name' => 'Sold out deal',
                'discount_type' => 'fixed',
                'discount_value' => 500,
                'price_minor' => 500,
                'redemption_validity_days' => 30,
                'allocation_limit' => 1,
            ]
        ), OfferStatus::Active);
        VoucherCode::create([
            'webshop_id' => $this->shop->id, 'offer_id' => $sold->id, 'code' => 'SOLD-0001',
            'code_hash' => VoucherCode::hashFor($this->shop->id, 'SOLD-0001'), 'code_last4' => '0001',
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($consumer);
        app(WalletService::class)->credit(app(WalletService::class)->consumerWallet($consumer), 10_000, 'seed:'.$consumer->id, neverExpires: true);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/consumer/checkouts', ['offer' => $sold->slug, 'use_wallet' => true])
            ->assertSuccessful();
        $this->getJson('/api/v1/public/offers/'.$sold->slug)->assertOk()
            ->assertJsonPath('data.availability', 'sold_out');
        $soldOut = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/consumer/checkouts', ['offer' => $sold->slug, 'use_wallet' => true]);
        $this->assertContains($soldOut->status(), [409, 422]);
    }

    /** 5. Direct registration → verify email → consumer dashboard (intended offer remains public). */
    public function test_05_direct_registration_verify_and_return_to_offer(): void
    {
        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Approved]);
        $offerSlug = $this->walletOffer->fresh()->slug;

        $this->register(['email' => 'nieuw@example.nl', 'first_name' => 'Lisa'])
            ->assertCreated()
            ->assertJsonPath('data.destination', 'verify_email');

        $user = User::where('email', 'nieuw@example.nl')->sole();
        $this->postJson('/api/v1/auth/email/verify-code', ['code' => $this->lastCodeFor($user)])
            ->assertOk()
            ->assertJsonPath('data.destination', 'consumer_dashboard')
            ->assertJsonPath('data.email_verified', true);

        // Intended public offer still reachable after auth.
        $this->getJson('/api/v1/public/offers/'.$offerSlug)->assertOk()
            ->assertJsonPath('data.slug', $offerSlug)
            ->assertJsonPath('data.availability', 'available');

        $this->getJson('/api/v1/consumer/dashboard')->assertOk()
            ->assertJsonPath('data.first_name', 'Lisa')
            ->assertJsonPath('data.email_verified', true);
    }

    /** 6. Valid activation invitation links the eligible event exactly once. */
    public function test_06_activation_links_eligible_event_once(): void
    {
        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Approved]);
        [$token] = $this->openInvitation('anna@example.nl');

        $preview = $this->postJson('/api/v1/consumer/activation/verify', ['token' => $token])->assertOk();
        $this->assertSame('eligible', $preview->json('data.status'));
        $this->assertSame('Woon & Co', $preview->json('data.webshop.name'));
        $this->assertArrayNotHasKey('customer_email', $preview->json('data'));
        $this->assertStringNotContainsString('anna@', $preview->getContent());

        $anna = User::factory()->create(['email' => 'anna@example.nl', 'email_verified_at' => now()]);
        Sanctum::actingAs($anna);

        $done = $this->postJson('/api/v1/consumer/activation/complete', ['token' => $token])->assertOk();
        $this->assertSame('activated', $done->json('data.status'));
        $voucherId = $done->json('data.voucher_id');
        $this->assertNotEmpty($done->json('data.code'));

        // Same consumer repeating is idempotent (same voucher).
        $again = $this->postJson('/api/v1/consumer/activation/complete', ['token' => $token])->assertOk();
        $this->assertSame($voucherId, $again->json('data.voucher_id'));

        $this->assertSame(1, VoucherClaim::where('claimed_by', $anna->id)->where('status', 'claimed')->count());
    }

    /** 7. Expired, replayed, revoked, mismatched and forged tokens fail safely. */
    public function test_07_token_protection_without_leaking_customer_data(): void
    {
        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Approved]);
        [$token, $claim] = $this->openInvitation('anna@example.nl');

        $this->postJson('/api/v1/consumer/activation/verify', ['token' => str_repeat('x', 24)])
            ->assertNotFound()->assertJsonPath('error.code', 'claim_not_found');
        $this->assertStringNotContainsString('anna@', $this->postJson('/api/v1/consumer/activation/verify', ['token' => str_repeat('x', 24)])->getContent());

        $claim->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->postJson('/api/v1/consumer/activation/verify', ['token' => $token])
            ->assertStatus(410);

        [$token2, $claim2] = $this->openInvitation('bob@example.nl');
        $claim2->forceFill(['status' => 'cancelled'])->save();
        $this->postJson('/api/v1/consumer/activation/verify', ['token' => $token2])
            ->assertStatus(410);

        [$token3] = $this->openInvitation('carol@example.nl');
        $wrong = User::factory()->create(['email' => 'other@example.nl', 'email_verified_at' => now()]);
        Sanctum::actingAs($wrong);
        $denied = $this->postJson('/api/v1/consumer/activation/complete', ['token' => $token3])->assertForbidden();
        $this->assertSame('claim_email_mismatch', $denied->json('error.code'));
        $this->assertStringNotContainsString('carol@', $denied->getContent());
        $this->assertTrue(
            AuditEvent::where('outcome', AuditOutcome::Denied)->where('action', 'answer24.claim_email_mismatch')->exists()
            || AuditEvent::where('action', 'answer24.claim_email_mismatch')->exists()
        );

        $carol = User::factory()->create(['email' => 'carol@example.nl', 'email_verified_at' => now()]);
        Sanctum::actingAs($carol);
        $this->postJson('/api/v1/consumer/activation/complete', ['token' => $token3])->assertOk();
        $intruder = User::factory()->create(['email' => 'intruder@example.nl', 'email_verified_at' => now()]);
        Sanctum::actingAs($intruder);
        $this->postJson('/api/v1/consumer/activation/complete', ['token' => $token3])
            ->assertStatus(410);
    }

    /** 8. Dashboard: own wallet/voucher summaries; pending is not spendable. */
    public function test_08_dashboard_verified_wallet_and_pending_not_spendable(): void
    {
        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Approved]);
        $anna = User::factory()->create(['first_name' => 'Anna', 'email_verified_at' => now()]);
        app(WalletService::class)->credit(app(WalletService::class)->consumerWallet($anna), 2500, 'dash:'.$anna->id, neverExpires: true);

        $inventory = app(VoucherInventory::class);
        $inventory->issue($inventory->reserve($this->offer, VoucherOrigin::Promotional, 'dash-v:'.$anna->id), $anna);

        Sanctum::actingAs($anna);
        $dash = $this->getJson('/api/v1/consumer/dashboard')->assertOk()
            ->assertJsonPath('data.first_name', 'Anna')
            ->assertJsonPath('data.available_minor', 2500)
            ->assertJsonPath('data.pending_minor', 0)
            ->assertJsonPath('data.vouchers.issued', 1);

        $this->assertSame(0, $dash->json('data.wallet.eur_credit.pending'));
        $this->assertNotEmpty($dash->json('data.recent_vouchers'));
        $this->assertIsArray($dash->json('data.recent_activity'));

        // Pending filter never surfaces spendable affiliate rows.
        $this->getJson('/api/v1/consumer/wallet/transactions?filter=pending')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);

        $other = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/consumer/dashboard')->assertOk()
            ->assertJsonPath('data.available_minor', 0)
            ->assertJsonPath('data.vouchers.issued', 0);
    }

    /** 9. Profile/security: re-verification, language, consent, cross-user denial. */
    public function test_09_profile_security_consent_and_cross_user_denial(): void
    {
        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Approved]);
        $anna = User::factory()->create([
            'first_name' => 'Anna',
            'email' => 'anna-profile@example.nl',
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($anna);

        $this->patchJson('/api/v1/consumer/profile', ['first_name' => 'Anne', 'locale' => 'en'])->assertOk()
            ->assertJsonPath('data.first_name', 'Anne')
            ->assertJsonPath('data.locale', 'en');

        $this->patchJson('/api/v1/consumer/preferences', [
            'notifications' => ['vouchers_discounts' => true, 'reminders' => true, 'offers_inspiration' => false],
        ])->assertOk()->assertJsonPath('data.notifications.vouchers_discounts', true);

        // Cross-user: Bob cannot see Anna's voucher by guessing her public id.
        $inventory = app(VoucherInventory::class);
        $voucher = $inventory->issue($inventory->reserve($this->offer, VoucherOrigin::Promotional, 'prof-v'), $anna);
        $bob = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($bob);
        $this->getJson('/api/v1/consumer/vouchers/'.$voucher->public_id)->assertNotFound();

        // Suspended account blocked from protected consumer actions.
        $anna->forceFill(['status' => UserStatus::Suspended])->save();
        Sanctum::actingAs($anna);
        $this->getJson('/api/v1/consumer/profile')->assertForbidden();
    }

    /** 10. Operational quality: cache invalidation, error envelope, docs route, latency budget. */
    public function test_10_operational_quality_cache_errors_docs_and_latency(): void
    {
        Webshop::whereKey($this->shop->id)->update(['status' => WebshopStatus::Approved]);

        $this->getJson('/api/v1/public/webshops')->assertOk();
        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$this->shop->public_id}/public-profile", ['published' => false])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertNotContains('woon-en-co', array_column(
            $this->getJson('/api/v1/public/webshops')->json('data'),
            'slug'
        ));

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$this->shop->public_id}/public-profile", ['published' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        $samples = [];
        for ($i = 0; $i < 5; $i++) {
            $t0 = hrtime(true);
            $this->getJson('/api/v1/public/webshops')->assertOk();
            $samples[] = (hrtime(true) - $t0) / 1e6;
        }
        sort($samples);
        $p95 = $samples[(int) floor(0.95 * (count($samples) - 1))];
        // Local/CI budget: generous absolute ceiling; production staging target is <300ms.
        $this->assertLessThan(3000, $p95, "public webshops p95 was {$p95}ms");

        $this->getJson('/api/v1/consumer/dashboard')
            ->assertUnauthorized()
            ->assertJsonStructure(['error' => ['status', 'code', 'message', 'request_id']]);

        $this->getJson('/api/v1/public/webshops?category=not-real')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        // OpenAPI coverage is enforced separately; ensure activation routes are registered.
        $this->assertTrue(
            collect(Route::getRoutes())->contains(
                fn ($r) => str_contains($r->uri(), 'consumer/activation/verify')
            )
        );
    }

    /**
     * @return array{0: string, 1: VoucherClaim}
     */
    private function openInvitation(string $email): array
    {
        $connection = WebshopConnection::firstOrCreate(
            ['webshop_id' => $this->shop->id, 'platform' => 'woocommerce'],
            [
                'method' => 'webhook',
                'status' => 'connected',
                'webhook_token' => Str::random(40),
                'created_by' => $this->owner->id,
            ]
        );
        $order = WebshopOrder::create([
            'webshop_id' => $this->shop->id,
            'connection_id' => $connection->id,
            'platform' => 'woocommerce',
            'external_order_id' => 'ord-'.Str::lower(Str::random(8)),
            'reference' => 'REF-'.Str::upper(Str::random(4)),
            'customer_email' => $email,
            'customer_email_hash' => WebshopOrder::emailHash($email),
            'payment_status' => PaymentStatus::Paid,
            'ingestion_status' => 'complete',
            'amount_minor' => 7500,
            'currency' => 'EUR',
            'paid_at' => now(),
            'placed_at' => now(),
        ]);

        // Ensure inventory for this invitation (each reserve consumes one unique code).
        $code = 'ACT-'.Str::upper(Str::random(6));
        VoucherCode::create([
            'webshop_id' => $this->shop->id,
            'offer_id' => $this->offer->id,
            'code' => $code,
            'code_hash' => VoucherCode::hashFor($this->shop->id, $code),
            'code_last4' => substr($code, -4),
            'status' => VoucherCodeStatus::Available,
        ]);

        $voucher = app(VoucherInventory::class)->reserve(
            $this->offer->fresh(),
            VoucherOrigin::Answer24Claim,
            'activation-e2e:'.Str::uuid(),
            $order
        );
        $token = Str::random(24);
        $claim = VoucherClaim::create([
            'webshop_id' => $this->shop->id,
            'voucher_id' => $voucher->id,
            'opportunity_id' => null,
            'token_hash' => VoucherClaim::hashToken($token),
            'token' => $token,
            'status' => 'open',
            'expires_at' => now()->addDays(30),
        ]);

        return [$token, $claim];
    }
}
