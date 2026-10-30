<?php

namespace Tests\Feature\Checkout;

use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherCheckoutPayment;
use App\Models\VoucherCode;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B11b: My Vouchers detail, merchant redemption, disputes and one-time
 * compensation (Trello #20 E2E 7, 8, 9).
 */
class VoucherDisputeTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $anna;

    private User $owner;

    private User $admin;

    private Webshop $shop;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopbonus.payments.mollie.key' => 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM']);
        Http::fake(['api.mollie.com/v2/*' => fn (HttpRequest $r) => Http::response(['id' => 're_'.substr(md5($r->header('Idempotency-Key')[0] ?? 'x'), 0, 8), 'status' => 'pending'], 201)]);
        $this->anna = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        $offers = app(OfferService::class);
        $this->offer = $offers->transition($offers->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), OfferAcquisition::WalletPurchase, [
            'name' => '€25 korting', 'discount_type' => 'fixed', 'discount_value' => 2500, 'min_order_minor' => 10000, 'price_minor' => 2000, 'redemption_validity_days' => 30,
        ]), OfferStatus::Active);
        foreach (['MEUBEL-0001', 'MEUBEL-0002', 'MEUBEL-0003'] as $code) {
            VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $this->offer->id, 'code' => $code, 'code_hash' => VoucherCode::hashFor($this->shop->id, $code), 'code_last4' => substr($code, -4)]);
        }
    }

    private function issued(?User $to = null): Voucher
    {
        $inventory = app(VoucherInventory::class);

        return $inventory->issue($inventory->reserve($this->offer, VoucherOrigin::Promotional, 'test:'.uniqid()), $to ?? $this->anna);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function ws(string $suffix): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}{$suffix}";
    }

    public function test_card20_e2e7_consumer_sees_only_own_vouchers_and_reveals_codes_audited(): void
    {
        $mine = $this->issued();
        $this->issued(User::factory()->create());
        Sanctum::actingAs($this->anna);

        $list = $this->getJson('/api/v1/consumer/vouchers?filter=active')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('••••'.$mine->code->code_last4, $list[0]['code_masked']);
        $this->assertStringNotContainsString('MEUBEL-', json_encode($list));

        $this->getJson("/api/v1/consumer/vouchers/{$mine->public_id}")->assertOk()->assertJsonPath('data.code', $mine->code->code)
            ->assertJsonPath('data.terms.min_order_minor', 10000)->assertJsonPath('data.expires_soon', false);
        $this->assertSame(1, AuditEvent::where('action', 'voucher.code_revealed')->count());
        $this->assertSame([], $this->getJson('/api/v1/consumer/vouchers?filter=used')->json('data'));

        $other = Voucher::where('consumer_user_id', '!=', $this->anna->id)->sole();
        $this->getJson("/api/v1/consumer/vouchers/{$other->public_id}")->assertNotFound();
        $this->getJson('/api/v1/consumer/vouchers?filter=reserved')->assertUnprocessable();

        $this->travel(20)->days();
        $this->assertTrue($this->getJson("/api/v1/consumer/vouchers/{$mine->public_id}")->json('data.expires_soon'));
    }

    public function test_card20_e2e8_merchant_redemption_is_verified_once_and_viewing_never_redeems(): void
    {
        $v = $this->issued();
        Sanctum::actingAs($this->anna);
        $this->getJson("/api/v1/consumer/vouchers/{$v->public_id}")->assertOk(); // "Copy code"
        $this->get('/api/v1/public/webshops/woon-co/visit'); // "Visit webshop" (not even listed here)
        $this->assertSame(VoucherStatus::Issued, $v->fresh()->status);

        Sanctum::actingAs($this->owner);
        $this->postJson($this->ws('/vouchers/redeem'), ['code' => 'meubel-000'.substr($v->code->code, -1), 'reference' => 'Order 5531'])
            ->assertOk()->assertJsonPath('data.status', 'redeemed')->assertJsonPath('data.source', 'merchant');
        $this->postJson($this->ws('/vouchers/redeem'), ['code' => $v->code->code])->assertStatus(409)->assertJsonPath('error.code', 'voucher_not_redeemable');
        $this->assertDatabaseHas('audit_events', ['action' => 'voucher.redemption_denied', 'resource_id' => (string) $v->id]);
        $this->postJson($this->ws('/vouchers/redeem'), ['code' => 'NOPE'])->assertNotFound();
        $this->asAdmin();
        $this->getJson('/api/v1/admin/financial-oversight?category=redemption_signals')->assertOk()
            ->assertJsonPath('data.0.id', $v->public_id)->assertJsonPath('data.0.denied_attempts', 1);
        Sanctum::actingAs($this->owner);

        $this->assertSame([$this->owner->id, 'Order 5531'], [$v->fresh()->redeemed_by, $v->fresh()->redemption_reference]);

        $expired = $this->issued();
        $this->travel(31)->days();
        $this->postJson($this->ws('/vouchers/redeem'), ['code' => $expired->code->code])->assertStatus(409)->assertJsonPath('error.code', 'voucher_expired');

        // Another webshop cannot redeem it; employees without vouchers.manage cannot either.
        $otherOwner = User::factory()->create();
        $other = $this->activeWebshop($otherOwner);
        Sanctum::actingAs($otherOwner);
        $this->postJson("/api/v1/workspaces/{$other->public_id}/vouchers/redeem", ['code' => $expired->code->code])->assertNotFound();
        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->postJson($this->ws('/vouchers/redeem'), ['code' => 'X'])->assertForbidden();
    }

    public function test_code_does_not_work_accepts_screenshot_once_and_serves_it_privately(): void
    {
        Storage::fake('local');
        $v = $this->issued();
        Sanctum::actingAs($this->anna);
        $file = UploadedFile::fake()->image('checkout-error.png', 640, 480);

        $id = $this->post('/api/v1/consumer/disputes', [
            'voucher_id' => $v->public_id,
            'reason' => 'code_invalid',
            'description' => 'Code rejected at checkout with this screenshot.',
            'screenshot' => $file,
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.has_screenshot', true)
            ->json('data.id');

        $this->get("/api/v1/consumer/disputes/{$id}/screenshot")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/v1/consumer/disputes/{$id}/screenshot")->assertNotFound();
    }

    public function test_card20_e2e9_dispute_review_and_wallet_compensation_once(): void
    {
        $v = $this->issued();
        Sanctum::actingAs($this->anna);
        $id = $this->postJson('/api/v1/consumer/disputes', ['voucher_id' => $v->public_id, 'reason' => 'code_invalid', 'description' => 'The webshop says the code does not exist.'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.has_screenshot', false)->json('data.id');
        $this->postJson('/api/v1/consumer/disputes', ['voucher_id' => $v->public_id, 'reason' => 'other', 'description' => 'Second report about the same'])->assertStatus(409);

        $this->asAdmin();
        $this->postJson("/api/v1/admin/disputes/{$id}/status", ['status' => 'waiting_for_merchant', 'note' => 'Asked the webshop'])->assertOk()->assertJsonPath('data.status', 'waiting_for_merchant');

        Sanctum::actingAs($this->owner);
        $merchant = $this->getJson($this->ws('/disputes'))->assertOk()->json('data.0');
        $this->assertSame([$id, $v->code->code_last4], [$merchant['id'], $merchant['code_last4']]);
        $this->assertStringNotContainsString($this->anna->email, json_encode($merchant));
        $this->postJson($this->ws("/disputes/{$id}/respond"), ['message' => 'The code was not imported correctly, sorry.'])->assertOk()->assertJsonPath('data.status', 'under_review');

        $this->asAdmin();
        $this->postJson("/api/v1/admin/disputes/{$id}/resolve", ['outcome' => 'resolved', 'compensation' => 'wallet_credit', 'amount_minor' => 2000, 'note' => 'Invalid code confirmed by merchant'])
            ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolution.amount_minor', 2000);
        $this->postJson("/api/v1/admin/disputes/{$id}/resolve", ['outcome' => 'resolved', 'compensation' => 'wallet_credit', 'amount_minor' => 2000, 'note' => 'Again'])
            ->assertStatus(409)->assertJsonPath('error.code', 'dispute_closed');

        $wallet = app(WalletService::class)->consumerWallet($this->anna);
        $this->assertSame(2000, $wallet->fresh()->available);
        $this->assertSame(1, WalletLedgerEntry::where('type', 'adjustment')->count());

        Sanctum::actingAs($this->anna);
        $timeline = $this->getJson("/api/v1/consumer/disputes/{$id}")->assertOk()->json('data.timeline');
        $this->assertSame(['opened', 'status_changed', 'merchant_response', 'status_changed', 'resolved', 'compensated'], array_column($timeline, 'type'));
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/consumer/disputes/{$id}")->assertNotFound();
    }

    public function test_replacement_and_refund_compensation_and_rejection(): void
    {
        $v = $this->issued();
        Sanctum::actingAs($this->anna);
        $first = $this->postJson('/api/v1/consumer/disputes', ['voucher_id' => $v->public_id, 'reason' => 'code_already_used', 'description' => 'Already used before I got it.'])->json('data.id');

        $this->asAdmin();
        $this->postJson("/api/v1/admin/disputes/{$first}/resolve", ['outcome' => 'rejected', 'compensation' => 'wallet_credit', 'amount_minor' => 1, 'note' => 'Invalid combination'])->assertUnprocessable();
        $replacement = $this->postJson("/api/v1/admin/disputes/{$first}/resolve", ['outcome' => 'resolved', 'compensation' => 'replacement', 'note' => 'New code issued'])->assertOk();
        $this->assertSame(2, Voucher::where('consumer_user_id', $this->anna->id)->where('status', VoucherStatus::Issued)->count());

        // Refund of the Mollie part of a paid checkout.
        $checkout = VoucherCheckout::create(['user_id' => $this->anna->id, 'offer_id' => $this->offer->id, 'webshop_id' => $this->shop->id, 'idempotency_key' => 'k-1', 'state' => 'completed',
            'price_minor' => 2000, 'wallet_part_minor' => 1000, 'payment_part_minor' => 1000, 'currency' => 'EUR', 'voucher_id' => $v->id, 'expires_at' => now()]);
        VoucherCheckoutPayment::create(['checkout_id' => $checkout->id, 'provider' => 'mollie', 'provider_ref' => 'tr_refundme1', 'amount_minor' => 1000, 'currency' => 'EUR', 'status' => 'paid', 'test_mode' => true]);
        Sanctum::actingAs($this->anna);
        $second = $this->postJson('/api/v1/consumer/disputes', ['checkout_id' => $checkout->public_id, 'reason' => 'terms_differ', 'description' => 'Discount was lower than advertised.'])->json('data.id');
        $this->asAdmin();
        $this->postJson("/api/v1/admin/disputes/{$second}/resolve", ['outcome' => 'resolved', 'compensation' => 'refund', 'amount_minor' => 5000, 'note' => 'Too much'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/disputes/{$second}/resolve", ['outcome' => 'resolved', 'compensation' => 'refund', 'note' => 'Refund the card payment'])->assertOk()->assertJsonPath('data.resolution.amount_minor', 1000);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments/tr_refundme1/refunds') && $r['amount'] === ['currency' => 'EUR', 'value' => '10.00'] && $r->hasHeader('Idempotency-Key'));
        $this->assertSame(1000, VoucherCheckoutPayment::where('provider_ref', 'tr_refundme1')->value('refunded_minor'));
    }

    public function test_paid_unfulfilled_checkout_is_refunded_once(): void
    {
        $checkout = VoucherCheckout::create(['user_id' => $this->anna->id, 'offer_id' => $this->offer->id, 'webshop_id' => $this->shop->id, 'idempotency_key' => 'k-2', 'state' => 'paid_unfulfilled',
            'price_minor' => 2000, 'wallet_part_minor' => 0, 'payment_part_minor' => 2000, 'currency' => 'EUR', 'expires_at' => now(), 'failure_reason' => 'reservation_lost']);
        VoucherCheckoutPayment::create(['checkout_id' => $checkout->id, 'provider' => 'mollie', 'provider_ref' => 'tr_lost00001', 'amount_minor' => 2000, 'currency' => 'EUR', 'status' => 'paid', 'test_mode' => true]);

        $this->asAdmin();
        $this->getJson('/api/v1/admin/checkouts?state=paid_unfulfilled')->assertOk()->assertJsonPath('data.0.id', $checkout->public_id);
        $this->postJson("/api/v1/admin/checkouts/{$checkout->public_id}/refund", ['note' => 'Inventory ran out after payment'])->assertOk()->assertJsonPath('data.state', 'refunded');
        $this->postJson("/api/v1/admin/checkouts/{$checkout->public_id}/refund", ['note' => 'Again please'])->assertStatus(409);
        Http::assertSentCount(1);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/checkouts/{$checkout->public_id}/refund", ['note' => 'Merchant tries'])->assertForbidden();
    }
}
