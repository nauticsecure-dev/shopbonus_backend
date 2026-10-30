<?php

namespace Tests\Feature\Checkout;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherStatus;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherCheckoutPayment;
use App\Models\VoucherCode;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Offers\OfferService;
use App\Services\Reliability\FinancialSources;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B11a: voucher checkout with wallet credit and Mollie (Trello #20 E2E 1, 2,
 * 4, 5, 6, 10; #23 tests 7 and 8). Mollie is faked over HTTP in test mode.
 */
class CheckoutTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $anna;

    private Offer $offer;

    /** Mollie payment status returned by the fake on GET. */
    private string $mollieStatus = 'open';

    /** Overrides the amount Mollie reports (a mismatching payment). */
    private ?string $mollieAmount = null;

    /** HTTP status Mollie answers the next payment creation requests with (null = 201). */
    private array $mollieCreateFailures = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopbonus.payments.mollie.key' => 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM']);
        $this->anna = User::factory()->create();
        $this->offer = $this->walletOffer(2000, ['WOON-PAID-0001', 'WOON-PAID-0002']);

        Http::fake(['api.mollie.com/v2/*' => function (HttpRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/payments')) {
                if (($failure = array_shift($this->mollieCreateFailures)) !== null) {
                    return Http::response(['status' => $failure, 'title' => 'Error'], $failure);
                }
                $id = 'tr_'.substr(md5((string) $request['metadata']['checkout_id']), 0, 10);

                return Http::response(['id' => $id, 'status' => 'open', 'amount' => $request['amount'], 'metadata' => $request['metadata'],
                    '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/'.substr($id, 3)]]], 201);
            }
            $payment = VoucherCheckoutPayment::where('provider_ref', basename(parse_url($request->url(), PHP_URL_PATH)))->firstOrFail();
            $checkout = VoucherCheckout::findOrFail($payment->checkout_id);

            return Http::response(['id' => $payment->provider_ref, 'status' => $this->mollieStatus,
                'amount' => ['currency' => 'EUR', 'value' => $this->mollieAmount ?? sprintf('%d.%02d', intdiv($checkout->payment_part_minor, 100), $checkout->payment_part_minor % 100)],
                'metadata' => ['checkout_id' => $checkout->public_id]]);
        }]);
    }

    private function walletOffer(int $price, array $codes, array $terms = []): Offer
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner, ['name' => 'Woon & Co']);
        $membership = $shop->memberships()->sole()->setRelation('webshop', $shop);
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($membership, OfferAcquisition::WalletPurchase, [
            'name' => '€25 korting', 'discount_type' => 'fixed', 'discount_value' => 2500, 'price_minor' => $price, 'redemption_validity_days' => 90, ...$terms,
        ]), OfferStatus::Active);
        foreach ($codes as $code) {
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $code, 'code_hash' => VoucherCode::hashFor($shop->id, $code),
                'code_last4' => substr($code, -4), 'reusable' => ($terms['code_mode'] ?? 'unique') === 'reusable']);
        }

        return $offer->refresh();
    }

    private function fund(int $amount, ?User $user = null): Wallet
    {
        $wallet = app(WalletService::class)->consumerWallet($user ?? $this->anna);
        app(WalletService::class)->credit($wallet, $amount, 'seed-'.uniqid());

        return $wallet;
    }

    private function buy(?string $key = null, array $body = []): TestResponse
    {
        Sanctum::actingAs($this->anna);

        return $this->withHeaders(['Idempotency-Key' => $key ?? 'buy-'.uniqid()])->postJson('/api/v1/consumer/checkouts', ['offer' => $this->offer->slug, ...$body]);
    }

    private function webhook(): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/api/v1/payments/mollie/webhook', ['id' => VoucherCheckoutPayment::latest('id')->value('provider_ref')], ['Accept' => 'application/json']);
    }

    public function test_card20_e2e1_and_card23_test7_wallet_only_purchase_is_atomic_and_idempotent(): void
    {
        $wallet = $this->fund(2000);

        $first = $this->buy('checkout-key-1')->assertCreated()->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.wallet_part_minor', 2000)->assertJsonPath('data.payment_part_minor', 0)->assertJsonPath('data.payment', null);
        $this->assertStringNotContainsString('WOON-PAID', $first->getContent()); // never a code in checkout responses
        $this->buy('checkout-key-1')->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame([0, 2000], [$wallet->fresh()->available, $wallet->fresh()->total_spent]);
        $voucher = Voucher::where('public_id', $first->json('data.voucher_id'))->sole();
        $this->assertSame([VoucherStatus::Issued, $this->anna->id], [$voucher->status, $voucher->consumer_user_id]);
        $this->assertSame(1, VoucherCode::where('status', VoucherCodeStatus::Issued)->count());
        $check = app(FinancialSources::class)->inspect(VoucherCheckout::sole());
        $this->assertSame('matched', $check['status'], json_encode($check));
        Http::assertNothingSent(); // points-only never touches Mollie
        $this->assertSame([], app(WalletService::class)->reconcile());
        $debit = WalletLedgerEntry::where('source_type', 'voucher_checkout')->where('type', 'debit')->sole();
        DB::table('voucher_checkouts')->delete(); // Synthetic orphan-source corruption.
        $orphan = app(FinancialSources::class)->inspect($debit);
        $this->assertContains('ledger_purchase_allocation', $orphan['evidence']['issues']);
    }

    public function test_checkout_history_keeps_the_title_that_was_bought(): void
    {
        $this->fund(2000);
        $id = $this->buy()->assertCreated()->assertJsonPath('data.offer.title', '€25 korting')->json('data.id');
        DB::table('offer_versions')->where('id', $this->offer->current_version_id)->update(['name' => 'Renamed later']);

        $this->getJson("/api/v1/consumer/checkouts/{$id}")->assertOk()->assertJsonPath('data.offer.title', '€25 korting');
    }

    public function test_card20_e2e2_blockers_stop_the_purchase_without_any_change(): void
    {
        $wallet = $this->fund(500);
        config(['shopbonus.payments.mollie.key' => null]);
        Sanctum::actingAs($this->anna);

        $this->postJson('/api/v1/consumer/checkouts/quote', ['offer' => $this->offer->slug])->assertOk()
            ->assertJsonPath('data.wallet_part_minor', 500)->assertJsonPath('data.payment_part_minor', 1500)
            ->assertJsonPath('data.shortfall_minor', 1500)->assertJsonPath('data.blockers', ['payments_unavailable']);
        $this->buy()->assertStatus(409)->assertJsonPath('error.code', 'checkout_blocked')->assertJsonPath('error.details.blockers', ['payments_unavailable']);

        app(OfferService::class)->transition($this->offer, OfferStatus::Paused);
        $this->buy()->assertStatus(409)->assertJsonPath('error.details.blockers', ['offer_not_active', 'payments_unavailable']);
        app(OfferService::class)->transition($this->offer->refresh(), OfferStatus::Active);

        VoucherCode::query()->update(['status' => VoucherCodeStatus::Invalid->value]);
        $this->fund(5000);
        $this->buy()->assertStatus(409)->assertJsonPath('error.details.blockers', ['sold_out']);

        $this->withHeaders(['Idempotency-Key' => 'x'])->postJson('/api/v1/consumer/checkouts', ['offer' => $this->offer->slug])->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
        $this->assertSame(0, VoucherCheckout::count());
        $this->assertSame([5500, 0], [$wallet->fresh()->available, $wallet->fresh()->reserved]);
    }

    public function test_card20_e2e5_and_card23_test8_mixed_checkout_finalises_only_on_verified_payment(): void
    {
        $wallet = $this->fund(1000);

        $checkout = $this->buy()->assertCreated()->assertJsonPath('data.state', 'pending_payment')
            ->assertJsonPath('data.wallet_part_minor', 1000)->assertJsonPath('data.payment_part_minor', 1000)
            ->assertJsonPath('data.payment.checkout_url', 'https://www.mollie.com/checkout/select-method/'.substr(VoucherCheckoutPayment::sole()->provider_ref, 3));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['amount'] === ['currency' => 'EUR', 'value' => '10.00']
            && $r['webhookUrl'] === route('api.v1.payments.mollie.webhook') && $r->hasHeader('Authorization', 'Bearer test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM'));
        $this->assertSame([0, 1000], [$wallet->fresh()->available, $wallet->fresh()->reserved]);
        $this->assertSame(1, VoucherCode::where('status', VoucherCodeStatus::Reserved)->count());

        // The consumer comes back from Mollie: the redirect proves nothing, the payment is still open.
        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/consumer/checkouts/'.$checkout->json('data.id'))->assertJsonPath('data.state', 'pending_payment');
        $this->webhook()->assertOk();
        $this->assertSame('pending_payment', VoucherCheckout::sole()->state);

        $this->mollieStatus = 'paid';
        $this->webhook()->assertOk();
        $this->webhook()->assertOk(); // Mollie retries: finalised once
        $c = VoucherCheckout::sole();
        $this->assertSame('completed', $c->state);
        $this->assertSame([0, 0, 1000], [$wallet->fresh()->available, $wallet->fresh()->reserved, $wallet->fresh()->total_spent]);
        $this->assertSame(1, Voucher::where('consumer_user_id', $this->anna->id)->where('status', VoucherStatus::Issued)->count());
        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/consumer/checkouts/'.$c->public_id)->assertJsonPath('data.state', 'completed')->assertJsonPath('data.payment.checkout_url', null);
    }

    public function test_an_unanswered_payment_request_is_retried_with_the_same_mollie_idempotency_key(): void
    {
        $this->fund(500);
        $this->mollieCreateFailures = [503];
        $this->buy('retry-checkout-0001')->assertStatus(502)->assertJsonPath('error.code', 'payment_provider_error');
        $checkout = VoucherCheckout::sole();
        $this->assertSame('pending_payment', $checkout->state); // outcome unknown: reservations kept for the retry
        $this->assertNull($checkout->payment);

        $this->buy('retry-checkout-0001')->assertSuccessful()->assertJsonPath('data.state', 'pending_payment');
        $this->assertSame(1, VoucherCheckout::count());
        $this->assertNotNull($checkout->fresh()->payment);
        $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => $r->method() === 'POST')
            ->map(fn (HttpRequest $r) => $r->header('Idempotency-Key')[0] ?? null)->unique()->values()->all();
        $this->assertSame(['checkout:'.$checkout->public_id], $keys);

        // A clear refusal (4xx) fails the checkout and releases everything.
        $this->mollieCreateFailures = [422];
        $this->buy('refused-checkout-0001')->assertStatus(502);
        $this->assertSame('failed', VoucherCheckout::where('idempotency_key', 'refused-checkout-0001')->value('state'));
    }

    public function test_card20_e2e6_failed_canceled_or_expired_payment_releases_everything(): void
    {
        foreach (['failed', 'canceled', 'expired'] as $status) {
            $wallet = $this->fund(500);
            $this->mollieStatus = 'open';
            $this->buy()->assertCreated();
            $this->mollieStatus = $status;
            $this->webhook()->assertOk();

            $c = VoucherCheckout::latest('id')->first();
            $this->assertSame([$status === 'expired' ? 'expired' : 'failed', 'payment_'.$status], [$c->state, $c->failure_reason]);
            $this->assertSame(0, $wallet->fresh()->reserved);
            $this->assertSame(VoucherStatus::Cancelled, Voucher::findOrFail($c->voucher_id)->status);
            $this->assertSame(2, VoucherCode::where('offer_id', $this->offer->id)->where('status', VoucherCodeStatus::Available)->count());
        }
        $this->assertSame(0, Voucher::where('status', VoucherStatus::Issued)->count());
    }

    public function test_abandoned_checkouts_expire_and_late_payments_are_still_honoured(): void
    {
        $wallet = $this->fund(500);
        $this->buy()->assertCreated();

        // Soft expiry while Mollie still says "open": kept (the consumer may still pay).
        $this->travel(31)->minutes();
        $this->artisan('shopbonus:expire-checkouts')->assertSuccessful();
        $this->assertSame('pending_payment', VoucherCheckout::sole()->state);

        // Paid late, before the hard limit: fulfilled.
        $this->mollieStatus = 'paid';
        $this->artisan('shopbonus:expire-checkouts')->assertSuccessful();
        $this->assertSame('completed', VoucherCheckout::sole()->state);
        $this->assertSame(500, $wallet->fresh()->total_spent);
    }

    public function test_payment_after_reservations_were_released_is_flagged_not_lost(): void
    {
        $wallet = $this->fund(500);
        $this->buy()->assertCreated();
        $this->mollieStatus = 'open';
        $this->travel(181)->minutes();
        $this->artisan('shopbonus:expire-checkouts')->assertSuccessful(); // hard limit: released
        $this->assertSame('expired', VoucherCheckout::sole()->state);
        $this->assertSame(500, $wallet->fresh()->available);

        $this->mollieStatus = 'paid'; // the consumer paid anyway
        $this->webhook()->assertOk();
        $c = VoucherCheckout::sole();
        $this->assertSame(['paid_unfulfilled', 'reservation_lost'], [$c->state, $c->failure_reason]);
        $this->assertSame([500, 0], [$wallet->fresh()->available, $wallet->fresh()->total_spent]); // wallet part not taken
        $this->assertSame(0, Voucher::where('status', VoucherStatus::Issued)->count());
    }

    public function test_card20_e2e10_forged_webhooks_and_other_users_cannot_change_anything(): void
    {
        $this->fund(500);
        $id = $this->buy()->assertCreated()->json('data.id');

        // A forged "paid" body is irrelevant: Mollie still says open.
        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/payments/mollie/webhook', ['id' => VoucherCheckoutPayment::sole()->provider_ref, 'status' => 'paid'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('pending_payment', VoucherCheckout::sole()->state);
        $this->post('/api/v1/payments/mollie/webhook', ['id' => 'tr_UNKNOWN000'], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/v1/payments/mollie/webhook', ['id' => "x' OR 1=1"], ['Accept' => 'application/json'])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/consumer/checkouts/{$id}")->assertNotFound();
    }

    public function test_a_paid_payment_for_another_amount_or_checkout_is_not_honoured(): void
    {
        $this->fund(500);
        $this->buy()->assertCreated();
        [$this->mollieStatus, $this->mollieAmount] = ['paid', '0.01'];

        $this->webhook()->assertOk();
        $this->assertSame('pending_payment', VoucherCheckout::sole()->state);
        $this->assertSame(0, Voucher::where('status', VoucherStatus::Issued)->count());
    }

    public function test_card20_e2e4_reusable_codes_issue_without_depleting_stock(): void
    {
        $this->offer = $this->walletOffer(1000, ['ZOMER10'], ['code_mode' => 'reusable']);
        $this->fund(3000);

        $this->buy()->assertCreated()->assertJsonPath('data.state', 'completed');
        $this->buy()->assertCreated()->assertJsonPath('data.state', 'completed');
        $this->buy()->assertCreated()->assertJsonPath('data.state', 'completed');

        $code = VoucherCode::where('offer_id', $this->offer->id)->sole();
        $this->assertSame([VoucherCodeStatus::Available, 3], [$code->status, $code->uses_count]);
        $this->assertSame(3, Voucher::where('offer_id', $this->offer->id)->where('status', VoucherStatus::Issued)->count());
    }

    public function test_live_mollie_keys_are_refused_by_default(): void
    {
        config(['shopbonus.payments.mollie.key' => 'live_abcdefghijklmnopqrstuvwxyzABCD']);
        $this->fund(500);
        $this->buy()->assertStatus(409)->assertJsonPath('error.details.blockers', ['payments_unavailable']);
    }
}
