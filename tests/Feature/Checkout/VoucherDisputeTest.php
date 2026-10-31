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
use App\Services\Checkout\CheckoutService;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        Http::fake(['api.mollie.com/v2/*' => fn (HttpRequest $r) => $r->method() === 'GET'
            ? Http::response(['id' => basename($r->url()), 'status' => $this->mollieRefundStatus, 'amount' => ['currency' => 'EUR', 'value' => '20.00']])
            : Http::response(['id' => 're_'.substr(md5($r->header('Idempotency-Key')[0] ?? 'x'), 0, 8), 'status' => 'pending'], 201)]);
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

    private string $mollieRefundStatus = 'pending';

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

    public function test_card20_e2e9_dispute_review_and_wallet_compensation_once(): void
    {
        $v = $this->issued();
        Sanctum::actingAs($this->anna);
        $id = $this->postJson('/api/v1/consumer/disputes', ['voucher_id' => $v->public_id, 'reason' => 'code_invalid', 'description' => 'The webshop says the code does not exist.'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
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

    public function test_merchant_dispute_history_is_paginated_newest_first(): void
    {
        Sanctum::actingAs($this->anna);
        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = $this->postJson('/api/v1/consumer/disputes', ['voucher_id' => $this->issued()->public_id, 'reason' => 'code_invalid', 'description' => "Report number {$i} about this code."])
                ->assertCreated()->json('data.id');
        }

        Sanctum::actingAs($this->owner);
        $first = $this->getJson($this->ws('/disputes?per_page=2'))->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3]);
        $this->assertSame([$ids[2], $ids[1]], array_column($first->json('data'), 'id'));
        $this->assertSame([$ids[0]], array_column($this->getJson($this->ws('/disputes?per_page=2&page=2'))->assertOk()->json('data'), 'id'));
        $this->getJson($this->ws('/disputes?status=resolved'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->ws('/disputes?per_page=101'))->assertStatus(422);
    }

    public function test_card23_code_does_not_work_report_keeps_screenshot_private_and_encrypted(): void
    {
        Storage::fake('local');
        $v = $this->issued();
        Sanctum::actingAs($this->anna);
        $disguised = UploadedFile::fake()->createWithContent('screenshot.png', '<?php echo "x"; ?>');
        $this->post('/api/v1/consumer/disputes', ['voucher_id' => $v->public_id, 'reason' => 'code_invalid', 'description' => 'The webshop rejects this code at checkout.',
            'screenshots' => [$disguised]], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['screenshots.0']]]);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $shot = UploadedFile::fake()->image('checkout error.png', 400, 300);
        $res = $this->post('/api/v1/consumer/disputes', ['voucher_id' => $v->public_id, 'reason' => 'code_invalid', 'description' => 'The webshop rejects this code at checkout.',
            'screenshots' => [$shot]], ['Accept' => 'application/json'])->assertCreated();
        $id = $res->json('data.id');
        $file = $res->json('data.screenshots.0');
        $this->assertSame(['checkout_error.png', 'image/png'], [$file['name'], $file['mime']]);
        $stored = Storage::disk('local')->get("dispute-evidence/{$id}/{$file['id']}.enc");
        $this->assertStringNotContainsString(substr((string) $shot->get(), 0, 16), (string) $stored);
        $this->assertSame(1, AuditEvent::where('action', 'dispute.opened')->value('metadata')['screenshots']);

        // A second report on the same voucher is refused and leaves no orphaned file.
        $this->post('/api/v1/consumer/disputes', ['voucher_id' => $v->public_id, 'reason' => 'other', 'description' => 'Another report about this code.',
            'screenshots' => [UploadedFile::fake()->image('again.png', 50, 50)]], ['Accept' => 'application/json'])->assertStatus(409);
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // Consumer and merchant cannot download; the merchant only learns that evidence exists.
        $this->get("/api/v1/admin/disputes/{$id}/evidence/{$file['id']}", ['Accept' => 'application/json'])->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->assertSame(1, $this->getJson($this->ws('/disputes'))->assertOk()->json('data.0.screenshots'));

        $this->asAdmin();
        $download = $this->get("/api/v1/admin/disputes/{$id}/evidence/{$file['id']}")->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertSame((string) $shot->get(), $download->getContent());
        $this->assertSame(1, AuditEvent::where('action', 'admin.dispute_evidence_downloaded')->count());
        $this->getJson("/api/v1/admin/disputes/{$id}/evidence/".Str::uuid())->assertNotFound();
        $this->getJson("/api/v1/admin/disputes/{$id}/evidence/..%2F..%2Fx")->assertNotFound();
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

        // Refund of the Mollie part of a paid checkout (a different purchase).
        $bought = $this->issued();
        $checkout = VoucherCheckout::create(['user_id' => $this->anna->id, 'offer_id' => $this->offer->id, 'webshop_id' => $this->shop->id, 'idempotency_key' => 'k-1', 'state' => 'completed',
            'price_minor' => 2000, 'wallet_part_minor' => 1000, 'payment_part_minor' => 1000, 'currency' => 'EUR', 'voucher_id' => $bought->id, 'expires_at' => now()]);
        VoucherCheckoutPayment::create(['checkout_id' => $checkout->id, 'provider' => 'mollie', 'provider_ref' => 'tr_refundme1', 'amount_minor' => 1000, 'currency' => 'EUR', 'status' => 'paid', 'test_mode' => true]);
        Sanctum::actingAs($this->anna);
        $second = $this->postJson('/api/v1/consumer/disputes', ['checkout_id' => $checkout->public_id, 'reason' => 'terms_differ', 'description' => 'Discount was lower than advertised.'])->json('data.id');
        $this->asAdmin();
        $this->postJson("/api/v1/admin/disputes/{$second}/resolve", ['outcome' => 'resolved', 'compensation' => 'refund', 'amount_minor' => 5000, 'note' => 'Too much'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/disputes/{$second}/resolve", ['outcome' => 'resolved', 'compensation' => 'refund', 'note' => 'Refund the card payment'])->assertOk()->assertJsonPath('data.resolution.amount_minor', 1000);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments/tr_refundme1/refunds') && $r['amount'] === ['currency' => 'EUR', 'value' => '10.00'] && $r->hasHeader('Idempotency-Key'));
        $this->assertSame(1000, VoucherCheckoutPayment::where('provider_ref', 'tr_refundme1')->value('refunded_minor'));
    }

    public function test_a_voucher_is_compensated_once_across_reports(): void
    {
        $this->issued(); // a second voucher keeps the offer from running out
        $v = $this->issued();
        Sanctum::actingAs($this->anna);
        $first = $this->postJson('/api/v1/consumer/disputes', ['voucher_id' => $v->public_id, 'reason' => 'code_invalid', 'description' => 'The webshop says the code does not exist.'])->json('data.id');
        $this->asAdmin();
        $this->postJson("/api/v1/admin/disputes/{$first}/resolve", ['outcome' => 'resolved', 'compensation' => 'wallet_credit', 'amount_minor' => 1000, 'note' => 'Invalid code confirmed'])->assertOk();

        // A later report about the same voucher (through its checkout) cannot be compensated again; closing it without compensation still works.
        $checkout = VoucherCheckout::create(['user_id' => $this->anna->id, 'offer_id' => $this->offer->id, 'webshop_id' => $this->shop->id, 'idempotency_key' => 'k-2', 'state' => 'completed',
            'price_minor' => 2000, 'wallet_part_minor' => 2000, 'payment_part_minor' => 0, 'currency' => 'EUR', 'voucher_id' => $v->id, 'expires_at' => now()]);
        Sanctum::actingAs($this->anna);
        $second = $this->postJson('/api/v1/consumer/disputes', ['checkout_id' => $checkout->public_id, 'reason' => 'terms_differ', 'description' => 'Still could not use this voucher.'])->assertCreated()->json('data.id');
        $this->asAdmin();
        $this->postJson("/api/v1/admin/disputes/{$second}/resolve", ['outcome' => 'resolved', 'compensation' => 'replacement', 'note' => 'Second compensation'])
            ->assertStatus(409)->assertJsonPath('error.code', 'voucher_already_compensated')->assertJsonPath('error.details.dispute_id', $first);
        $this->postJson("/api/v1/admin/disputes/{$second}/resolve", ['outcome' => 'resolved', 'compensation' => 'none', 'note' => 'Already compensated'])->assertOk();

        $this->assertSame(1000, app(WalletService::class)->consumerWallet($this->anna)->fresh()->available);
        $this->assertFalse(Voucher::where('idempotency_key', 'like', 'dispute-replacement:%')->exists());
    }

    public function test_paid_unfulfilled_checkout_is_refunded_once(): void
    {
        $checkout = VoucherCheckout::create(['user_id' => $this->anna->id, 'offer_id' => $this->offer->id, 'webshop_id' => $this->shop->id, 'idempotency_key' => 'k-2', 'state' => 'paid_unfulfilled',
            'price_minor' => 2000, 'wallet_part_minor' => 0, 'payment_part_minor' => 2000, 'currency' => 'EUR', 'expires_at' => now(), 'failure_reason' => 'reservation_lost']);
        VoucherCheckoutPayment::create(['checkout_id' => $checkout->id, 'provider' => 'mollie', 'provider_ref' => 'tr_lost00001', 'amount_minor' => 2000, 'currency' => 'EUR', 'status' => 'paid', 'test_mode' => true]);

        $this->asAdmin();
        $this->getJson('/api/v1/admin/checkouts?state=paid_unfulfilled')->assertOk()->assertJsonPath('data.0.id', $checkout->public_id);
        // Mollie accepts the refund as pending: not reported as refunded yet, and not refundable twice.
        $this->postJson("/api/v1/admin/checkouts/{$checkout->public_id}/refund", ['note' => 'Inventory ran out after payment'])->assertOk()->assertJsonPath('data.state', 'refund_pending');
        $this->postJson("/api/v1/admin/checkouts/{$checkout->public_id}/refund", ['note' => 'Again please'])->assertStatus(409);
        Http::assertSentCount(1);

        $this->mollieRefundStatus = 'refunded';
        $this->assertSame(1, app(CheckoutService::class)->syncRefunds());
        $this->assertSame('refunded', $checkout->fresh()->state);
        $this->assertSame(0, app(CheckoutService::class)->syncRefunds());

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/checkouts/{$checkout->public_id}/refund", ['note' => 'Merchant tries'])->assertForbidden();
    }

    public function test_a_failed_refund_returns_the_checkout_to_refundable_with_a_new_idempotency_key(): void
    {
        $checkout = VoucherCheckout::create(['user_id' => $this->anna->id, 'offer_id' => $this->offer->id, 'webshop_id' => $this->shop->id, 'idempotency_key' => 'k-3', 'state' => 'paid_unfulfilled',
            'price_minor' => 2000, 'wallet_part_minor' => 0, 'payment_part_minor' => 2000, 'currency' => 'EUR', 'expires_at' => now(), 'failure_reason' => 'reservation_lost']);
        VoucherCheckoutPayment::create(['checkout_id' => $checkout->id, 'provider' => 'mollie', 'provider_ref' => 'tr_lost00002', 'amount_minor' => 2000, 'currency' => 'EUR', 'status' => 'paid', 'test_mode' => true]);
        $this->asAdmin();
        $this->postJson("/api/v1/admin/checkouts/{$checkout->public_id}/refund", ['note' => 'Inventory ran out after payment'])->assertOk()->assertJsonPath('data.state', 'refund_pending');

        $this->mollieRefundStatus = 'failed';
        app(CheckoutService::class)->syncRefunds();
        $payment = VoucherCheckoutPayment::where('provider_ref', 'tr_lost00002')->sole();
        $this->assertSame(['paid_unfulfilled', 'failed', 0], [$checkout->fresh()->state, $payment->refund_status, $payment->refunded_minor]);

        $this->postJson("/api/v1/admin/checkouts/{$checkout->public_id}/refund", ['note' => 'Second attempt after failure'])->assertOk()->assertJsonPath('data.state', 'refund_pending');
        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => $r->method() === 'POST')->map(fn (HttpRequest $r) => $r->header('Idempotency-Key')[0])->values();
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }
}
