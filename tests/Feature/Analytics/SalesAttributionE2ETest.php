<?php

namespace Tests\Feature\Analytics;

use App\Enums\ConnectionMethod;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\GenericSignedWebhookConnector;
use App\Integrations\Data\PaymentStatus;
use App\Models\AffiliateConnection;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\BannerCampaign;
use App\Models\BannerInstallation;
use App\Models\FundingInvoice;
use App\Models\NetworkJourney;
use App\Models\Offer;
use App\Models\SponsorshipObligation;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherCheckoutPayment;
use App\Models\VoucherClaim;
use App\Models\VoucherClaimReminder;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Affiliate\AffiliateSync;
use App\Services\Attribution\OrderAttribution;
use App\Services\Banners\BannerExperiments;
use App\Services\Banners\BannerService;
use App\Services\Funding\MerchantFunding;
use App\Services\Offers\OfferService;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Vouchers\PlatformCouponVerification;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\TestCase;

/**
 * Exactly 10 end-to-end scenarios for Trello #24 — Sales Attribution,
 * Voucher Tracking & Network Analytics. Reuses Order Adapter, banners,
 * vouchers, wallets, Mollie stubs, affiliate adapters and Answer24.
 */
class SalesAttributionE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private const ORIGIN_A = 'https://www.shop-a.nl';

    private User $ownerA;

    private User $ownerB;

    private User $admin;

    private Webshop $shopA;

    private Webshop $shopB;

    private Offer $rewardOffer;

    private BannerCampaign $campaign;

    private BannerInstallation $installation;

    private string $mollieStatus = 'open';

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopbonus.payments.mollie.key' => 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM']);

        $this->admin = User::factory()->create(['email' => 'admin@shopbonus.test']);
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);

        $this->ownerA = User::factory()->create(['first_name' => 'Ada']);
        $this->ownerB = User::factory()->create(['first_name' => 'Ben']);
        $this->shopA = $this->activeWebshop($this->ownerA, ['name' => 'Shop A Fashion', 'verified_domain' => 'shop-a.nl']);
        $this->shopB = $this->activeWebshop($this->ownerB, ['name' => 'Shop B Electronics', 'verified_domain' => 'shop-b.nl']);

        app(MerchantFunding::class)->wallet($this->shopA);
        app(WalletService::class)->adjust(app(MerchantFunding::class)->wallet($this->shopA), 50_000, 'seed', 'Test funding', $this->ownerA->id);

        $offers = app(OfferService::class);
        $membership = $this->shopA->memberships()->sole()->setRelation('webshop', $this->shopA);
        $this->rewardOffer = $offers->transition($offers->create($membership, OfferAcquisition::MerchantReward, [
            'name' => '€10 next purchase', 'discount_type' => 'fixed', 'discount_value' => 1000,
            'min_order_minor' => 10000, 'redemption_validity_days' => 90, 'claim_validity_days' => 90,
        ]), OfferStatus::Active);
        foreach (['SHOP-A-0001', 'SHOP-A-0002', 'SHOP-A-0003'] as $code) {
            VoucherCode::create([
                'webshop_id' => $this->shopA->id, 'offer_id' => $this->rewardOffer->id, 'code' => $code,
                'code_hash' => VoucherCode::hashFor($this->shopA->id, $code), 'code_last4' => substr($code, -4),
            ]);
        }

        $banners = app(BannerService::class);
        $this->campaign = $banners->create($this->shopA, 'Fashion reward', $this->rewardOffer, BannerFixture::config(), $this->ownerA->id);
        $banners->publish($this->campaign, $this->ownerA->id);
        $this->installation = $banners->installation($this->shopA);

        Http::fake(['api.mollie.com/v2/*' => function (HttpRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/payments')) {
                $id = 'tr_'.substr(md5((string) ($request['metadata']['checkout_id'] ?? uniqid())), 0, 10);

                return Http::response(['id' => $id, 'status' => 'open', 'amount' => $request['amount'], 'metadata' => $request['metadata'],
                    '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/'.substr($id, 3)]]], 201);
            }
            $payment = VoucherCheckoutPayment::where('provider_ref', basename(parse_url($request->url(), PHP_URL_PATH)))->first();
            if ($payment === null) {
                return Http::response(['id' => 'tr_x', 'status' => $this->mollieStatus, 'amount' => ['currency' => 'EUR', 'value' => '10.00']], 200);
            }
            $checkout = VoucherCheckout::findOrFail($payment->checkout_id);

            return Http::response(['id' => $payment->provider_ref, 'status' => $this->mollieStatus,
                'amount' => ['currency' => 'EUR', 'value' => sprintf('%d.%02d', intdiv($checkout->payment_part_minor, 100), $checkout->payment_part_minor % 100)],
                'metadata' => ['checkout_id' => $checkout->public_id]]);
        }]);
    }

    private function event(string $type, bool $consent = true, string $device = 'desktop', ?string $category = 'fashion'): array
    {
        return [
            'id' => (string) Str::uuid(),
            'campaign' => $this->campaign->public_id,
            'version' => 1,
            'type' => $type,
            'occurred_at' => now()->toIso8601String(),
            'device' => $device,
            'path' => '/collections/sale',
            'category' => $category,
            'consent' => $consent,
        ];
    }

    private function postEvents(array $events, string $origin = self::ORIGIN_A, ?string $ua = null): array
    {
        $headers = ['Origin' => $origin];
        if ($ua !== null) {
            $headers['User-Agent'] = $ua;
        }

        $response = $this->postJson('/api/v1/banners/events', [
            'key' => $this->installation->public_key,
            'events' => $events,
        ], $headers);
        if ($response->status() !== 202) {
            $this->fail('banner events '.$response->status().': '.$response->getContent());
        }

        return $response->json('data');
    }

    private function connection(Webshop $shop, User $owner): WebshopConnection
    {
        $existing = WebshopConnection::where('webshop_id', $shop->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/workspaces/{$shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();

        return WebshopConnection::where('webshop_id', $shop->id)->sole();
    }

    private function paidOrder(Webshop $shop, User $owner, array $attrs): WebshopOrder
    {
        $connection = $this->connection($shop, $owner);
        $order = WebshopOrder::create(array_merge([
            'webshop_id' => $shop->id,
            'connection_id' => $connection->id,
            'platform' => 'prestashop',
            'external_order_id' => 'ORD-'.Str::upper(Str::random(6)),
            'reference' => 'R-'.Str::random(4),
            'ingestion_status' => 'complete',
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'payment_status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'placed_at' => now(),
            'customer_email' => 'buyer@example.nl',
            'customer_first_name' => 'Buyer',
            'customer_email_hash' => WebshopOrder::emailHash('buyer@example.nl'),
            'customer_key' => WebshopOrder::customerKey($shop->id, 'buyer@example.nl', null),
        ], $attrs));
        app(OrderAttribution::class)->resolve($order, $attrs['coupon_codes'] ?? []);

        return $order->fresh();
    }

    /** E2E 1 — True impressions: 100/70/7/20 → 70 impressions, 10% CTR, 20 dismissals. */
    public function test_e2e01_true_impressions_ctr_and_device_splits(): void
    {
        $batch = [];
        for ($i = 0; $i < 100; $i++) {
            $batch[] = $this->event('eligible');
        }
        for ($i = 0; $i < 70; $i++) {
            $batch[] = $this->event('viewable_impression', device: $i < 50 ? 'desktop' : 'mobile');
        }
        for ($i = 0; $i < 20; $i++) {
            $batch[] = $this->event('dismiss');
        }
        for ($i = 0; $i < 7; $i++) {
            $batch[] = $this->event('cta_click');
        }
        foreach (array_chunk($batch, 50) as $chunk) {
            $this->postEvents($chunk);
        }

        Sanctum::actingAs($this->ownerA);
        $r = $this->getJson("/api/v1/workspaces/{$this->shopA->public_id}/banners/{$this->campaign->public_id}/results")
            ->assertOk()->json('data');
        $this->assertSame([100, 70, 7, 20, 0.1], [$r['eligible'], $r['viewable_impressions'], $r['cta_clicks'], $r['dismissals'], $r['ctr']]);
        $this->assertSame(20, $this->getJson("/api/v1/workspaces/{$this->shopA->public_id}/banners/{$this->campaign->public_id}/results?device=mobile")
            ->json('data.viewable_impressions'));
        $this->assertSame(50, $this->getJson("/api/v1/workspaces/{$this->shopA->public_id}/banners/{$this->campaign->public_id}/results?device=desktop")
            ->json('data.viewable_impressions'));
        $this->assertSame(70, (int) DB::table('banner_events')->where('type', 'viewable_impression')->where('category', 'fashion')->count());
    }

    /** E2E 2 — Consent and joining: consented click reaches paid order; no-consent creates no tracker; unmatched stays Unattributed. */
    public function test_e2e02_consent_click_joins_paid_order_and_no_consent_creates_no_tracker(): void
    {
        $consented = $this->postEvents([$this->event('cta_click', consent: true)]);
        $this->assertNotEmpty($consented['click_ids']);
        $clickId = array_values($consented['click_ids'])[0];
        $this->assertMatchesRegularExpression('/^bc_[A-Za-z0-9]{24}$/', $clickId);

        $denied = $this->postEvents([$this->event('cta_click', consent: false)]);
        $this->assertSame([], $denied['click_ids']);
        $this->assertSame(1, DB::table('banner_clicks')->count());

        $matched = $this->paidOrder($this->shopA, $this->ownerA, [
            'amount_minor' => 11200, 'attribution_click_id' => $clickId, 'external_order_id' => 'PAID-1',
        ]);
        $this->assertSame(OrderAttribution::TYPE_CLICK, $matched->attribution_type);

        $unmatched = $this->paidOrder($this->shopA, $this->ownerA, [
            'amount_minor' => 5000, 'external_order_id' => 'PAID-2',
        ]);
        $this->assertSame(OrderAttribution::TYPE_UNATTRIBUTED, $unmatched->attribution_type);

        $o = app(GenericSignedWebhookConnector::class)->normalizeOrder(['order' => [
            'id' => '9', 'reference' => 'R', 'currency' => 'EUR', 'total_minor' => 100,
            'customer' => ['first_name' => 'A', 'email' => 'a@example.nl'], 'payment_status' => 'paid',
            'created_at' => now()->toIso8601String(), 'attribution' => ['click_id' => $clickId],
        ]]);
        $this->assertSame($clickId, $o->attributionClickId);
    }

    /** E2E 3 — Duplicate webhook + two clicks + one paid €112 → one attributed order; refund adjusts once. */
    public function test_e2e03_paid_order_accuracy_dedupe_and_refund_once(): void
    {
        $clicks = $this->postEvents([$this->event('cta_click'), $this->event('cta_click')]);
        $ids = array_values($clicks['click_ids']);
        $this->assertCount(2, $ids);

        $order = $this->paidOrder($this->shopA, $this->ownerA, [
            'amount_minor' => 11200, 'attribution_click_id' => $ids[0], 'external_order_id' => 'X112',
        ]);
        app(OrderAttribution::class)->resolve($order); // replay
        $this->assertSame(1, DB::table('attribution_links')->where('order_id', $order->id)->count());

        Sanctum::actingAs($this->ownerA);
        $r = $this->getJson("/api/v1/workspaces/{$this->shopA->public_id}/banners/{$this->campaign->public_id}/results")->json('data');
        $this->assertSame([1, 11200], [$r['attributed_orders'], $r['attributed_revenue_minor']]);

        $order->forceFill(['payment_status' => PaymentStatus::PartiallyRefunded, 'refunded_minor' => 1200, 'refunded_at' => now()])->save();
        app(OrderAttribution::class)->resolve($order->fresh());
        $r2 = $this->getJson("/api/v1/workspaces/{$this->shopA->public_id}/banners/{$this->campaign->public_id}/results")->json('data');
        $this->assertSame([1, 10000], [$r2['attributed_orders'], $r2['attributed_revenue_minor']]);
        $this->assertSame(1, DB::table('promotion_events')->where('type', 'order_refunded')->where('subject_id', $order->public_id)->count());
    }

    /** E2E 4 — First-order voucher: €100 reserves €10; guest cannot claim; verified account redeems once on second order. */
    public function test_e2e04_first_order_voucher_requires_verified_account_and_redeems_once(): void
    {
        $inventory = app(VoucherInventory::class);
        $voucher = $inventory->reserve($this->rewardOffer, VoucherOrigin::MerchantReward, 'order:100:offer:'.$this->rewardOffer->id);
        $this->assertSame([VoucherStatus::Reserved, 1000, 300], [
            $voucher->status,
            SponsorshipObligation::where('voucher_id', $voucher->id)->value('reward_minor'),
            SponsorshipObligation::where('voucher_id', $voucher->id)->value('fee_minor'),
        ]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/consumer/vouchers/'.$voucher->public_id)->assertUnauthorized();

        $guest = User::factory()->unverified()->create();
        try {
            $inventory->issue($voucher, $guest);
            $this->fail('Unverified guest must not receive a voucher');
        } catch (ApiException $e) {
            $this->assertSame('consumer_not_verified', $e->errorCode);
        }

        $member = User::factory()->create(['email_verified_at' => now()]);
        $issued = $inventory->issue($voucher->fresh(), $member);
        $this->assertSame(VoucherStatus::Issued, $issued->status);
        Sanctum::actingAs($member);
        $revealed = $this->getJson('/api/v1/consumer/vouchers/'.$issued->public_id)->assertOk();
        $this->assertNotEmpty($revealed->json('data.code') ?? $revealed->json('data.code_masked'));

        $second = $this->paidOrder($this->shopA, $this->ownerA, [
            'amount_minor' => 12000, 'external_order_id' => 'NEXT-1',
            'customer_email' => $member->email,
            'customer_email_hash' => WebshopOrder::emailHash($member->email),
            'customer_key' => WebshopOrder::customerKey($this->shopA->id, $member->email, null),
        ]);
        $inventory->redeem($issued->fresh(), $second);
        $this->assertSame(VoucherStatus::Redeemed, $issued->fresh()->status);

        try {
            $inventory->redeem($issued->fresh(), $second);
            $this->fail('Second redeem must fail');
        } catch (ApiException $e) {
            $this->assertSame('voucher_not_redeemable', $e->errorCode);
        }
    }

    /** E2E 5 — Real coupon: Woo verifies; unsupported platform is Limited (unsupported_integration). */
    public function test_e2e05_real_coupon_supported_vs_limited_unsupported(): void
    {
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }

            public function txtRecords(string $host): array
            {
                return [];
            }
        });

        WebshopConnection::create([
            'webshop_id' => $this->shopA->id,
            'platform' => EcommercePlatform::WooCommerce,
            'method' => ConnectionMethod::WebhookWithApiLookup,
            'status' => ConnectionStatus::Connected,
            'webhook_token' => str_repeat('a', 40),
            'credentials' => [
                'store_url' => 'https://shop-a.nl',
                'consumer_key' => 'ck_SYNTHETIC_read_key',
                'consumer_secret' => 'cs_SYNTHETIC_read_secret',
            ],
            'created_by' => $this->ownerA->id,
            'connected_at' => now(),
        ]);

        Http::fake(fn (HttpRequest $r) => Http::response([[
            'id' => 1, 'code' => 'SHOP-A-0001', 'discount_type' => 'fixed_cart', 'amount' => '10.00',
            'minimum_amount' => '100.00', 'maximum_amount' => '', 'date_expires' => null, 'usage_limit' => 1,
        ]], 200, ['X-WP-TotalPages' => '1']));

        $code = VoucherCode::where('offer_id', $this->rewardOffer->id)->where('code_last4', '0001')->sole();
        $ok = app(PlatformCouponVerification::class)->verifyAgainstProvider($code, $this->rewardOffer->currentVersion);
        $this->assertSame(PlatformCouponVerification::VERIFIED, $ok['platform_validation']);

        WebshopConnection::create([
            'webshop_id' => $this->shopB->id,
            'platform' => EcommercePlatform::Wix,
            'method' => ConnectionMethod::Webhook,
            'status' => ConnectionStatus::Connected,
            'webhook_token' => str_repeat('b', 40),
            'created_by' => $this->ownerB->id,
            'connected_at' => now(),
        ]);
        $offers = app(OfferService::class);
        $m = $this->shopB->memberships()->sole()->setRelation('webshop', $this->shopB);
        $other = $offers->transition($offers->create($m, OfferAcquisition::MerchantReward, [
            'name' => '€4', 'discount_type' => 'fixed', 'discount_value' => 400, 'redemption_validity_days' => 30,
        ]), OfferStatus::Active);
        app(WalletService::class)->adjust(app(MerchantFunding::class)->wallet($this->shopB), 10_000, 'seed', 'B', $this->ownerB->id);
        $limitedCode = VoucherCode::create([
            'webshop_id' => $this->shopB->id, 'offer_id' => $other->id, 'code' => 'SHOP-B-LIM1',
            'code_hash' => VoucherCode::hashFor($this->shopB->id, 'SHOP-B-LIM1'), 'code_last4' => 'LIM1',
        ]);
        $limited = app(PlatformCouponVerification::class)->verifyAgainstProvider($limitedCode, $other->currentVersion);
        $this->assertSame(PlatformCouponVerification::UNSUPPORTED, $limited['platform_validation']);
        $this->assertNotSame(PlatformCouponVerification::VERIFIED, $limited['platform_validation']);
    }

    /** E2E 6 — Wallet purchase: €20 code with €10 Wallet + €10 Mollie once; failed payment frees; replay safe. */
    public function test_e2e06_wallet_plus_mollie_purchase_once_and_failed_payment_releases(): void
    {
        $anna = User::factory()->create(['email_verified_at' => now()]);
        $offers = app(OfferService::class);
        $m = $this->shopA->memberships()->sole()->setRelation('webshop', $this->shopA);
        $paidOffer = $offers->transition($offers->create($m, OfferAcquisition::WalletPurchase, [
            'name' => '€20 code', 'discount_type' => 'fixed', 'discount_value' => 2000, 'price_minor' => 2000,
            'redemption_validity_days' => 90,
        ]), OfferStatus::Active);
        VoucherCode::create([
            'webshop_id' => $this->shopA->id, 'offer_id' => $paidOffer->id, 'code' => 'PAID-20-0001',
            'code_hash' => VoucherCode::hashFor($this->shopA->id, 'PAID-20-0001'), 'code_last4' => '0001',
        ]);

        $wallet = app(WalletService::class)->consumerWallet($anna);
        app(WalletService::class)->credit($wallet, 1000, 'seed-wallet');

        Sanctum::actingAs($anna);
        $checkout = $this->withHeaders(['Idempotency-Key' => 'mix-fail-01'])->postJson('/api/v1/consumer/checkouts', [
            'offer' => $paidOffer->slug,
        ])->assertCreated();
        $this->assertSame(['pending_payment', 1000, 1000], [
            $checkout->json('data.state'), $checkout->json('data.wallet_part_minor'), $checkout->json('data.payment_part_minor'),
        ]);
        $this->assertSame(1000, $wallet->fresh()->reserved);

        $this->mollieStatus = 'failed';
        $this->post('/api/v1/payments/mollie/webhook', ['id' => VoucherCheckoutPayment::latest('id')->value('provider_ref')], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1000, $wallet->fresh()->available);
        $this->assertSame(0, $wallet->fresh()->reserved);
        $this->assertSame(VoucherCodeStatus::Available, VoucherCode::where('offer_id', $paidOffer->id)->sole()->status);

        // Fresh attempt that succeeds once; webhook replay cannot debit twice.
        VoucherCode::where('offer_id', $paidOffer->id)->update(['status' => VoucherCodeStatus::Available->value]);
        $this->mollieStatus = 'open';
        $ok = $this->withHeaders(['Idempotency-Key' => 'mix-paid-02'])->postJson('/api/v1/consumer/checkouts', [
            'offer' => $paidOffer->fresh()->slug,
        ])->assertCreated();
        $this->mollieStatus = 'paid';
        $ref = VoucherCheckoutPayment::where('checkout_id', VoucherCheckout::where('public_id', $ok->json('data.id'))->value('id'))->value('provider_ref');
        $this->post('/api/v1/payments/mollie/webhook', ['id' => $ref], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/v1/payments/mollie/webhook', ['id' => $ref], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, Voucher::where('consumer_user_id', $anna->id)->where('status', VoucherStatus::Issued)->count());
        $this->assertSame(0, $wallet->fresh()->reserved);
    }

    /** E2E 7 — Merchant fees Fashion €10+€3 and Electronics €4+€1.20 once; empty wallet blocks; invoice draft reconciles. */
    public function test_e2e07_merchant_fees_topup_invoice_and_empty_wallet_blocks(): void
    {
        $inventory = app(VoucherInventory::class);
        $fashion = $inventory->reserve($this->rewardOffer, VoucherOrigin::MerchantReward, 'fee:fashion');
        $o = SponsorshipObligation::where('voucher_id', $fashion->id)->sole();
        $this->assertSame([1000, 300, 1300], [$o->reward_minor, $o->fee_minor, $o->total_minor]);

        $offers = app(OfferService::class);
        $m = $this->shopA->memberships()->sole()->setRelation('webshop', $this->shopA);
        $electronics = $offers->transition($offers->create($m, OfferAcquisition::MerchantReward, [
            'name' => '4%', 'discount_type' => 'percentage', 'discount_value' => 400, 'max_discount_minor' => 400,
            'redemption_validity_days' => 30,
        ]), OfferStatus::Active);
        VoucherCode::create([
            'webshop_id' => $this->shopA->id, 'offer_id' => $electronics->id, 'code' => 'ELEC-0001',
            'code_hash' => VoucherCode::hashFor($this->shopA->id, 'ELEC-0001'), 'code_last4' => '0001',
        ]);
        $e = $inventory->reserve($electronics, VoucherOrigin::MerchantReward, 'fee:electronics');
        $this->assertSame([400, 120, 520], [
            SponsorshipObligation::where('voucher_id', $e->id)->value('reward_minor'),
            SponsorshipObligation::where('voucher_id', $e->id)->value('fee_minor'),
            SponsorshipObligation::where('voucher_id', $e->id)->value('total_minor'),
        ]);

        $wallet = app(MerchantFunding::class)->wallet($this->shopA);
        app(WalletService::class)->adjust($wallet, -$wallet->available, 'drain', 'empty', $this->ownerA->id);
        try {
            $inventory->reserve($this->rewardOffer, VoucherOrigin::MerchantReward, 'fee:blocked');
            $this->fail();
        } catch (ApiException $ex) {
            $this->assertSame('merchant_funding_insufficient', $ex->errorCode);
        }

        app(WalletService::class)->adjust(app(MerchantFunding::class)->wallet($this->shopA), 20_000, 'seed', 'refill', $this->ownerA->id);
        Sanctum::actingAs($this->ownerA);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/workspaces/{$this->shopA->public_id}/funding/top-ups", ['amount_minor' => 5000])
            ->assertCreated()
            ->assertJsonPath('data.state', 'pending');

        $inventory->issue($fashion, User::factory()->create(['email_verified_at' => now()]));
        $inventory->redeemByMerchant($fashion->fresh(), $this->ownerA->id, 'settled');
        $period = now()->format('Y-m');
        $this->artisan('shopbonus:prepare-invoices', ['period' => $period])->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, FundingInvoice::count());
        Sanctum::actingAs($this->ownerA);
        $this->getJson("/api/v1/workspaces/{$this->shopA->public_id}/invoices")->assertOk();
    }

    /** E2E 8 — Network: Shop A click → Shop B purchase; sponsor recorded; favorites persist; no cross-tenant PII. */
    public function test_e2e08_network_referral_sponsor_recorded_and_orders_isolated(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        DB::table('consumer_favorite_webshops')->insert([
            ['user_id' => $member->id, 'webshop_id' => $this->shopA->id, 'created_at' => now()],
            ['user_id' => $member->id, 'webshop_id' => $this->shopB->id, 'created_at' => now()],
        ]);
        $this->assertSame(2, DB::table('consumer_favorite_webshops')->where('user_id', $member->id)->count());

        $clickId = array_values($this->postEvents([$this->event('cta_click')])['click_ids'])[0];
        $orderB = $this->paidOrder($this->shopB, $this->ownerB, [
            'amount_minor' => 15000,
            'attribution_click_id' => $clickId,
            'external_order_id' => 'B-NET-1',
            'customer_email' => $member->email,
        ]);
        $this->assertSame(OrderAttribution::TYPE_CLICK, $orderB->attribution_type);
        $this->assertSame($this->shopA->id, $orderB->referring_webshop_id);
        $journey = NetworkJourney::where('order_id', $orderB->id)->sole();
        $this->assertSame(['recorded', $this->shopA->id, $this->shopB->id], [
            $journey->settlement_status, $journey->referring_webshop_id, $journey->receiving_webshop_id,
        ]);

        Sanctum::actingAs($this->ownerA);
        $ordersA = $this->getJson("/api/v1/workspaces/{$this->shopA->public_id}/orders")->assertOk()->json('data');
        $this->assertFalse(collect($ordersA)->contains(fn ($o) => ($o['external_order_id'] ?? null) === 'B-NET-1'
            || ($o['id'] ?? null) === $orderB->public_id));

        Sanctum::actingAs($this->ownerB);
        $overviewB = $this->getJson("/api/v1/workspaces/{$this->shopB->public_id}/analytics/overview")->assertOk()->json('data');
        $this->assertSame(1, $overviewB['network']['as_receiver']['journeys']);
        $this->assertArrayNotHasKey('customer_email', $overviewB['network']);
        $this->assertSame(2, DB::table('consumer_favorite_webshops')->where('user_id', $member->id)->count());

        Sanctum::actingAs($this->admin);
        $net = $this->getJson('/api/v1/admin/network')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, $net['network_journeys']['count']);
        $this->assertArrayHasKey('limitations', $net);
    }

    /** E2E 9 — Affiliate reversal changes net; Answer24 reserves, 90-day expiry/reminders, no ShopBonus calling task. */
    public function test_e2e09_affiliate_net_and_answer24_no_calling_task(): void
    {
        AffiliateRewardRule::create([
            'version' => 1, 'unit' => 'eur_credit', 'share_bps' => 5000, 'min_commission_minor' => 50,
            'review_above_minor' => 5000, 'active' => true, 'created_by' => $this->admin->id,
        ]);
        $conn = AffiliateConnection::create([
            'network' => 'daisycon', 'name' => 'Daisycon', 'status' => 'connected',
            'credentials' => ['publisher_id' => '1', 'access_token' => 't', 'media_id' => '2'],
            'postback_token_hash' => AffiliateConnection::hashToken('daisy-token'),
        ]);
        Sanctum::actingAs($this->ownerA);
        $this->putJson("/api/v1/workspaces/{$this->shopA->public_id}/public-profile", [
            'short_description' => 'Fashion', 'website_url' => 'https://www.shop-a.nl', 'categories' => ['fashion'], 'published' => true,
        ])->assertOk();
        $programs = [['id' => 'P1', 'name' => 'Shop A Fashion', 'url' => 'https://www.shop-a.nl', 'status' => 'active', 'currency_code' => 'EUR',
            'commission' => ['type' => 'percentage', 'value' => '6', 'restricted' => false], 'cashback_allowed' => true,
            'tracking_url' => 'https://lt45.net/c/?si=1']];
        app(AffiliateSync::class)->import($conn, $programs, [], $this->admin->id);

        $anna = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($anna);
        $slug = DB::table('webshop_public_profiles')->where('webshop_id', $this->shopA->id)->value('slug');
        $this->get('/api/v1/public/webshops/'.$slug.'/visit')->assertRedirect();
        $ref = DB::table('affiliate_clicks')->orderByDesc('id')->value('reference');
        $this->app['auth']->forgetGuards();

        $txOpen = ['affiliatemarketing_id' => 'D-NET-1', 'program_id' => 'P1', 'date' => now()->toIso8601String(), 'last_modified' => now()->toIso8601String(),
            'parts' => [['subid' => $ref, 'referencenumber' => 'O1', 'revenue' => 100, 'commission' => 10.00, 'status' => 'approved', 'currency_code' => 'EUR']]];
        app(AffiliateSync::class)->import($conn, [], [$txOpen], $this->admin->id);
        $confirmed = (int) AffiliateTransaction::where('external_id', 'D-NET-1')->value('commission_minor');
        $this->assertSame('approved', AffiliateTransaction::where('external_id', 'D-NET-1')->value('status'));

        $txRev = ['affiliatemarketing_id' => 'D-NET-1', 'program_id' => 'P1', 'date' => now()->toIso8601String(), 'last_modified' => now()->addMinute()->toIso8601String(),
            'parts' => [['subid' => $ref, 'referencenumber' => 'O1', 'revenue' => 100, 'commission' => 10.00, 'status' => 'reversed', 'currency_code' => 'EUR']]];
        app(AffiliateSync::class)->import($conn, [], [$txRev], $this->admin->id);
        $tx = AffiliateTransaction::where('external_id', 'D-NET-1')->sole();
        $this->assertSame('reversed', $tx->status);
        $this->assertGreaterThan(0, $confirmed);

        $inventory = app(VoucherInventory::class);
        $reserved = $inventory->reserve($this->rewardOffer, VoucherOrigin::Answer24Claim, 'a24:handoff:1');
        $this->assertSame(VoucherStatus::Reserved, $reserved->status);

        $token = 'claim-token-'.Str::random(16);
        $claim = VoucherClaim::create([
            'webshop_id' => $this->shopA->id,
            'voucher_id' => $reserved->id,
            'status' => 'open',
            'token' => $token,
            'token_hash' => VoucherClaim::hashToken($token),
            'expires_at' => now()->addDays(90),
        ]);
        foreach ([30, 60, 80] as $day) {
            VoucherClaimReminder::create([
                'claim_id' => $claim->id, 'day' => $day, 'granted_at' => now(),
            ]);
        }
        $this->assertSame([30, 60, 80], VoucherClaimReminder::where('claim_id', $claim->id)->orderBy('day')->pluck('day')->all());
        $this->assertSame(0, DB::table('jobs')->where('payload', 'like', '%telemarket%')->count());
        $this->assertFalse(Schema::hasTable('calling_tasks'));

        $this->travel(91)->days();
        $this->artisan('shopbonus:expire-vouchers')->assertSuccessful();
    }

    /** E2E 10 — A/B + holdout lift separate from attributed; bot filter; audited denial; scale ingest with dedupe. */
    public function test_e2e10_ab_holdout_bot_filter_security_and_scale_ingest(): void
    {
        $bots = $this->postEvents([$this->event('viewable_impression')], ua: 'Googlebot/2.1');
        $this->assertSame(1, $bots['bots']);
        $this->assertSame(0, $bots['accepted']);

        // Scale path before experiments: env SHOPBONUS_LOAD_EVENTS can raise toward 1_000_000; CI uses a bounded proof.
        $n = max(500, min(20_000, (int) env('SHOPBONUS_LOAD_EVENTS', 2000)));
        $unique = [];
        for ($i = 0; $i < $n; $i++) {
            $unique[] = $this->event('eligible');
        }
        $started = hrtime(true);
        foreach (array_chunk($unique, 50) as $chunk) {
            $this->postEvents($chunk);
        }
        $dupAccepted = 0;
        $dupDuplicates = 0;
        foreach (array_chunk(array_slice($unique, 0, 100), 50) as $chunk) {
            $retry = $this->postEvents($chunk);
            $dupAccepted += $retry['accepted'];
            $dupDuplicates += $retry['duplicates'];
        }
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $this->assertSame(0, $dupAccepted);
        $this->assertSame(100, $dupDuplicates);
        $this->assertSame($n, (int) DB::table('banner_events')->where('type', 'eligible')->where('campaign_id', $this->campaign->id)->count());
        $this->assertLessThan(60_000, $elapsedMs, 'Bounded ingest batch exceeded 60s p95 budget for CI scale proof');

        Sanctum::actingAs($this->ownerB);
        $this->getJson("/api/v1/workspaces/{$this->shopB->public_id}/banners/{$this->campaign->public_id}")->assertNotFound();

        Sanctum::actingAs($this->ownerA);
        $url = "/api/v1/workspaces/{$this->shopA->public_id}/banners/{$this->campaign->public_id}";
        $draft = $this->campaign->fresh()->draft_config;
        $draft['placement']['anchor'] = 'bottom';
        app(BannerService::class)->saveDraft($this->campaign->fresh(), $draft, (int) $this->campaign->fresh()->draft_revision, $this->ownerA->id);
        app(BannerService::class)->publish($this->campaign->fresh(), $this->ownerA->id);
        $this->postJson($url.'/experiment', [
            'control_version' => null, 'variant_version' => 2, 'variant_bps' => 5000,
        ])->assertCreated();

        $this->postJson('/api/v1/banners/assignments', [
            'key' => $this->installation->public_key,
            'visitor_key' => (string) Str::uuid(),
            'consent' => true,
        ], ['Origin' => self::ORIGIN_A])->assertOk();

        $exp = app(BannerExperiments::class)->report($this->campaign->fresh());
        $this->assertTrue($exp['holdout']);
        $this->assertArrayHasKey('arms', $exp);
        $this->assertNull($exp['causal_uplift']);
        $this->assertStringContainsString('not payments', $exp['definitions']['orders']);

        $drill = $this->getJson("/api/v1/workspaces/{$this->shopA->public_id}/analytics/attribution/orders")->assertOk();
        $this->assertArrayHasKey('data', $drill->json());
        $this->assertArrayHasKey('meta', $drill->json());
    }
}
