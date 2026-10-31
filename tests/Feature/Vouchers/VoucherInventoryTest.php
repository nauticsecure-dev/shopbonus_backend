<?php

namespace Tests\Feature\Vouchers;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Integrations\Data\PaymentStatus;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\VoucherEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\RedemptionMatcher;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

/**
 * B06b voucher lifecycle (Trello #7 E2E 3, 6, 8 partial; #23 one voucher system).
 */
class VoucherInventoryTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase, SignsWebhooks;

    private User $owner;

    private Webshop $shop;

    private Offer $offer;

    private VoucherInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner);
        $this->inventory = app(VoucherInventory::class);
        $this->offer = $this->activeOffer(['allocation_limit' => null]);
    }

    private function activeOffer(array $terms = []): Offer
    {
        $membership = $this->shop->memberships()->sole()->setRelation('webshop', $this->shop)->setRelation('user', $this->owner);
        $offers = app(OfferService::class);
        $offer = $offers->create($membership, OfferAcquisition::MerchantReward, [
            'name' => '€10 korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 10000,
            'redemption_validity_days' => 90, ...$terms,
        ]);

        return $offers->transition($offer, OfferStatus::Active);
    }

    private function codes(Offer $offer, array $codes): void
    {
        foreach ($codes as $code) {
            VoucherCode::create(['webshop_id' => $offer->webshop_id, 'offer_id' => $offer->id, 'code' => $code,
                'code_hash' => VoucherCode::hashFor($offer->webshop_id, $code), 'code_last4' => substr($code, -4)]);
        }
    }

    public function test_e2e6_full_lifecycle_available_reserved_issued_redeemed_once(): void
    {
        $this->codes($this->offer, ['WOON-AAAA-0001']);
        $consumer = User::factory()->create();

        $voucher = $this->inventory->reserve($this->offer, VoucherOrigin::Answer24Claim, 'handoff:1');
        $this->assertSame(VoucherStatus::Reserved, $voucher->status);
        $this->assertSame(VoucherCodeStatus::Reserved, VoucherCode::sole()->status);
        $this->assertTrue($voucher->claim_expires_at->between(now()->addDays(89), now()->addDays(91))); // 90-day claim window

        $voucher = $this->inventory->issue($voucher, $consumer);
        $this->assertSame(VoucherStatus::Issued, $voucher->status);
        $this->assertSame($consumer->id, $voucher->consumer_user_id);
        $this->assertSame(VoucherCodeStatus::Issued, VoucherCode::sole()->status);

        // Redemption is recorded only from a verified paid order whose coupon matches (real webhook).
        Sanctum::actingAs($this->owner);
        $r = $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/connections", ['platform' => 'woocommerce'])->assertCreated();
        $path = (string) parse_url($r->json('data.setup.webhook_url'), PHP_URL_PATH);
        $secret = $r->json('data.secrets.webhook_secret');
        $send = function (string $delivery) use ($path, $secret) {
            $body = json_encode($this->wooOrder(['coupon_lines' => [['id' => 1, 'code' => 'woon-aaaa-0001', 'discount' => '10.00']]]));
            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
            foreach ($this->wooHeaders($body, $secret, $delivery) as $k => $v) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
            }

            return $this->call('POST', $path, [], [], [], $server, $body);
        };
        $send('d-1')->assertStatus(202);
        $send('d-2')->assertStatus(202); // same order delivered again (order.updated)

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Redeemed, $voucher->status);
        $this->assertSame(WebshopOrder::sole()->id, $voucher->redeemed_order_id);
        $this->assertSame(VoucherCodeStatus::Redeemed, VoucherCode::sole()->status);

        // Repeated redemption is rejected.
        try {
            $this->inventory->redeem($voucher, WebshopOrder::sole());
            $this->fail('Second redemption must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('voucher_not_redeemable', $e->errorCode);
        }

        $this->assertSame(['reserved', 'code_issued', 'issued', 'code_redeemed', 'redeemed'],
            VoucherEvent::whereNotNull('voucher_id')->orderBy('id')->pluck('type')->all());
    }

    public function test_redemption_needs_a_paid_order_of_the_same_webshop(): void
    {
        $this->codes($this->offer, ['WOON-BBBB-0001']);
        $voucher = $this->inventory->issue($this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'k1'), User::factory()->create());

        $otherShop = $this->activeWebshop(User::factory()->create());
        $connection = WebshopConnection::create(['webshop_id' => $otherShop->id, 'platform' => 'other', 'method' => 'webhook', 'status' => 'connected',
            'webhook_token' => str_repeat('t', 40), 'created_by' => $this->owner->id]);
        $foreign = WebshopOrder::create(['webshop_id' => $otherShop->id, 'connection_id' => $connection->id, 'platform' => 'other',
            'external_order_id' => '1', 'payment_status' => PaymentStatus::Paid, 'ingestion_status' => 'complete']);

        $this->expectException(ApiException::class);
        $this->inventory->redeem($voucher, $foreign);
    }

    public function test_e2e3_issued_voucher_keeps_original_terms_after_the_offer_is_edited(): void
    {
        $this->codes($this->offer, ['WOON-CCCC-0001', 'WOON-CCCC-0002']);
        $v1 = $this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'order:1:offer:1');

        $membership = $this->shop->memberships()->sole()->setRelation('webshop', $this->shop);
        $edited = app(OfferService::class)->update($membership, $this->offer->fresh(), ['discount_value' => 1500]);
        $this->assertSame(2, $edited->currentVersion->version);

        $v2 = $this->inventory->reserve($this->offer->fresh(), VoucherOrigin::MerchantReward, 'order:2:offer:1');

        $this->assertSame(1000, $v1->fresh()->offerVersion->discount_value);
        $this->assertSame(1000, $v1->fresh()->terms_snapshot['discount_value']);
        $this->assertSame(1500, $v2->offerVersion->discount_value);
        $this->assertNotSame($v1->offer_version_id, $v2->offer_version_id);
    }

    public function test_e2e8_partial_one_reward_per_order_via_idempotency_key(): void
    {
        $this->codes($this->offer, ['WOON-DDDD-0001', 'WOON-DDDD-0002']);

        $first = $this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'order:42:offer:'.$this->offer->id);
        $replay = $this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'order:42:offer:'.$this->offer->id);

        $this->assertTrue($first->is($replay));
        $this->assertSame(1, Voucher::count());
        $this->assertSame(1, VoucherCode::where('status', 'available')->count());
    }

    public function test_out_of_stock_inactive_offer_and_allocation_limit(): void
    {
        try {
            $this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'k-empty');
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame('offer_out_of_stock', $e->errorCode);
        }

        $limited = $this->activeOffer(['allocation_limit' => 1]);
        $this->codes($limited, ['LIM-0001', 'LIM-0002']);
        $this->inventory->reserve($limited, VoucherOrigin::MerchantReward, 'lim-1');
        try {
            $this->inventory->reserve($limited, VoucherOrigin::MerchantReward, 'lim-2');
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame('offer_allocation_exhausted', $e->errorCode);
        }

        app(OfferService::class)->transition($limited->fresh(), OfferStatus::Paused);
        try {
            $this->inventory->reserve($limited->fresh(), VoucherOrigin::MerchantReward, 'lim-3');
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame('offer_not_active', $e->errorCode);
        }
    }

    public function test_paused_offer_keeps_issued_vouchers_valid(): void
    {
        $this->codes($this->offer, ['PAUSE-0001']);
        $voucher = $this->inventory->issue($this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'p1'), User::factory()->create());

        app(OfferService::class)->transition($this->offer->fresh(), OfferStatus::Paused);

        $this->assertSame(VoucherStatus::Issued, $voucher->fresh()->status);
    }

    public function test_release_returns_the_code_and_unclaimed_reservations_expire_after_90_days(): void
    {
        $this->codes($this->offer, ['REL-0001', 'EXP-0002']);
        $released = $this->inventory->release($this->inventory->reserve($this->offer, VoucherOrigin::Answer24Claim, 'h1'), 'handoff_cancelled');
        $this->assertSame(VoucherStatus::Cancelled, $released->status);
        $this->assertSame(2, VoucherCode::where('status', 'available')->count());

        $pending = $this->inventory->reserve($this->offer, VoucherOrigin::Answer24Claim, 'h2');
        $this->travel(91)->days();
        $this->artisan('shopbonus:expire-vouchers')->assertSuccessful();

        $this->assertSame(VoucherStatus::Cancelled, $pending->fresh()->status);
        $this->assertSame('claim_expired', $pending->fresh()->cancel_reason);
        $this->assertSame(2, VoucherCode::where('status', 'available')->count());

        // A claim after the window is refused.
        $late = $this->inventory->reserve($this->offer, VoucherOrigin::Answer24Claim, 'h3');
        $this->travel(91)->days();
        $this->expectException(ApiException::class);
        $this->inventory->issue($late, User::factory()->create());
    }

    public function test_issued_vouchers_expire_after_redemption_validity(): void
    {
        $this->codes($this->offer, ['VAL-0001']);
        $voucher = $this->inventory->issue($this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'v1'), User::factory()->create());

        $this->travel(91)->days();
        $this->artisan('shopbonus:expire-vouchers')->assertSuccessful();

        $this->assertSame(VoucherStatus::Expired, $voucher->fresh()->status);
        $this->assertSame(VoucherCodeStatus::Expired, VoucherCode::sole()->status);
    }

    public function test_unverified_consumers_cannot_receive_vouchers(): void
    {
        $this->codes($this->offer, ['UNV-0001']);
        $voucher = $this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'u1');

        $this->expectException(ApiException::class);
        $this->inventory->issue($voucher, User::factory()->unverified()->create());
    }

    public function test_reusable_code_serves_many_vouchers(): void
    {
        $reusable = $this->activeOffer(['code_mode' => 'reusable', 'name' => 'ZOMER10']);
        VoucherCode::create(['webshop_id' => $reusable->webshop_id, 'offer_id' => $reusable->id, 'code' => 'ZOMER10', 'reusable' => true,
            'code_hash' => VoucherCode::hashFor($reusable->webshop_id, 'ZOMER10'), 'code_last4' => 'ER10']);

        $a = $this->inventory->reserve($reusable, VoucherOrigin::Promotional, 'r1');
        $b = $this->inventory->reserve($reusable, VoucherOrigin::Promotional, 'r2');

        $this->assertSame($a->voucher_code_id, $b->voucher_code_id);
        $this->assertSame(2, VoucherCode::sole()->uses_count);
        $this->assertSame(VoucherCodeStatus::Available, VoucherCode::sole()->status);
    }

    private function paidOrder(string $email, string $reference, ?\DateTimeInterface $placedAt = null): WebshopOrder
    {
        $connection = WebshopConnection::firstOrCreate(['webshop_id' => $this->shop->id, 'platform' => 'other'],
            ['method' => 'webhook', 'status' => 'connected', 'webhook_token' => str_repeat('m', 40), 'created_by' => $this->owner->id]);

        return WebshopOrder::create(['webshop_id' => $this->shop->id, 'connection_id' => $connection->id, 'platform' => 'other',
            'external_order_id' => $reference, 'reference' => $reference, 'customer_email' => $email, 'customer_email_hash' => WebshopOrder::emailHash($email),
            'payment_status' => PaymentStatus::Paid, 'ingestion_status' => 'complete', 'placed_at' => $placedAt ?? now(), 'paid_at' => $placedAt ?? now()]);
    }

    public function test_a_reusable_code_redeems_only_the_buyers_own_voucher(): void
    {
        $reusable = $this->activeOffer(['code_mode' => 'reusable', 'name' => 'ZOMER10']);
        VoucherCode::create(['webshop_id' => $reusable->webshop_id, 'offer_id' => $reusable->id, 'code' => 'ZOMER10', 'reusable' => true,
            'code_hash' => VoucherCode::hashFor($reusable->webshop_id, 'ZOMER10'), 'code_last4' => 'ER10']);
        $anna = User::factory()->create(['email' => 'anna@example.nl']);
        $bram = User::factory()->create(['email' => 'bram@example.nl']);
        $annas = $this->inventory->issue($this->inventory->reserve($reusable, VoucherOrigin::Promotional, 'r-anna'), $anna);
        $brams = $this->inventory->issue($this->inventory->reserve($reusable, VoucherOrigin::Promotional, 'r-bram'), $bram);
        $matcher = app(RedemptionMatcher::class);

        // A stranger using the public code redeems nobody's voucher.
        $this->assertSame(0, $matcher->match($this->paidOrder('stranger@example.nl', 'ORD-1'), ['zomer10']));
        $this->assertSame(1, $matcher->match($this->paidOrder('Bram@Example.nl', 'ORD-2'), ['ZOMER10']));

        $this->assertSame(VoucherStatus::Issued, $annas->fresh()->status);
        $this->assertSame(VoucherStatus::Redeemed, $brams->fresh()->status);
        $this->assertSame(VoucherCodeStatus::Available, VoucherCode::sole()->status);
    }

    public function test_an_order_placed_after_expiry_does_not_redeem_the_voucher(): void
    {
        $this->codes($this->offer, ['WOON-CCCC-0001']);
        $consumer = User::factory()->create(['email' => 'late@example.nl']);
        $voucher = $this->inventory->issue($this->inventory->reserve($this->offer, VoucherOrigin::MerchantReward, 'late'), $consumer);

        $this->assertSame(0, app(RedemptionMatcher::class)->match($this->paidOrder('late@example.nl', 'ORD-LATE', $voucher->expires_at->addDay()), ['WOON-CCCC-0001']));
        $this->assertSame(VoucherStatus::Issued, $voucher->fresh()->status);

        $this->assertSame(1, app(RedemptionMatcher::class)->match($this->paidOrder('late@example.nl', 'ORD-ON-TIME', $voucher->expires_at->subDay()), ['WOON-CCCC-0001']));
        $this->assertSame(VoucherStatus::Redeemed, $voucher->fresh()->status);
    }
}
