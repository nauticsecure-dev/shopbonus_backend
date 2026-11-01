<?php

namespace Tests\Feature\Checkout;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherStatus;
use App\Enums\WalletUnit;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCheckout;
use App\Models\VoucherCode;
use App\Models\WalletLedgerEntry;
use App\Services\Checkout\CheckoutService;
use App\Services\Offers\OfferService;
use App\Services\Reliability\FinancialSources;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class PointsCheckoutTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $buyer;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->buyer = User::factory()->create();
        $shop = $this->activeWebshop(User::factory()->create());
        $offers = app(OfferService::class);
        $this->offer = $offers->transition($offers->create($shop->memberships()->sole(), OfferAcquisition::WalletPurchase, [
            'name' => 'Points voucher', 'discount_type' => 'fixed', 'discount_value' => 1000,
            'price_points' => 250, 'redemption_validity_days' => 30,
        ]), OfferStatus::Active);
        foreach (['POINTS-1', 'POINTS-2'] as $code) {
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $this->offer->id, 'code' => $code,
                'code_hash' => VoucherCode::hashFor($shop->id, $code), 'code_last4' => substr($code, -4)]);
        }
    }

    public function test_points_purchase_captures_only_points_and_replays_once(): void
    {
        $wallets = app(WalletService::class);
        $points = $wallets->consumerWallet($this->buyer, WalletUnit::Points);
        $euros = $wallets->consumerWallet($this->buyer);
        $wallets->credit($points, 400, 'points-fixture');
        $wallets->credit($euros, 10000, 'euros-fixture');
        Sanctum::actingAs($this->buyer);
        $body = ['offer' => $this->offer->slug, 'wallet_unit' => 'points', 'price_points' => 1];
        $this->postJson('/api/v1/consumer/checkouts/quote', $body)->assertOk()
            ->assertJsonPath('data.price_points', 250)->assertJsonPath('data.price_minor', 0)->assertJsonPath('data.payment_part_minor', 0);
        $response = $this->withHeader('Idempotency-Key', 'points-purchase-1')->postJson('/api/v1/consumer/checkouts', $body)
            ->assertCreated()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.wallet_part_points', 250)
            ->assertJsonPath('data.payment', null);
        $id = $response->json('data.id');
        $this->postJson('/api/v1/consumer/checkouts', $body)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(150, $points->fresh()->available);
        $this->assertSame(10000, $euros->fresh()->available);
        $this->assertSame(1, WalletLedgerEntry::where('wallet_id', $points->id)->where('type', 'debit')->count());
        $this->assertSame(1, Voucher::count());
        $this->assertSame(250, Voucher::sole()->terms_snapshot['price_points']);
        $this->assertSame('matched', app(FinancialSources::class)->inspect(VoucherCheckout::sole())['status']);
        Http::assertNothingSent();
    }

    public function test_insufficient_available_points_never_use_pending_reserved_euros_or_mollie(): void
    {
        config(['shopbonus.payments.mollie.key' => 'test_fixture']);
        $wallets = app(WalletService::class);
        $points = $wallets->consumerWallet($this->buyer, WalletUnit::Points);
        $wallets->credit($points, 300, 'points-fixture');
        $wallets->reserve($points, 100, 'other-purchase');
        $wallets->addPending($points, 1000, 'pending-fixture');
        $wallets->credit($wallets->consumerWallet($this->buyer), 10000, 'euros-fixture');
        Sanctum::actingAs($this->buyer);
        $this->withHeader('Idempotency-Key', 'points-shortfall-1')->postJson('/api/v1/consumer/checkouts', [
            'offer' => $this->offer->slug, 'wallet_unit' => 'points',
        ])->assertConflict()->assertJsonPath('error.details.blockers.0', 'insufficient_points');
        $this->assertSame(0, VoucherCheckout::count());
        $this->assertSame(0, Voucher::count());
        $this->assertSame(200, $points->fresh()->available);
        Http::assertNothingSent();
    }

    public function test_unit_validation_missing_euro_price_and_cross_user_access(): void
    {
        $this->postJson('/api/v1/consumer/checkouts', ['offer' => $this->offer->slug])->assertUnauthorized();
        Sanctum::actingAs($this->buyer);
        $this->withHeader('Idempotency-Key', 'points-validation')->postJson('/api/v1/consumer/checkouts', [
            'offer' => $this->offer->slug, 'wallet_unit' => 'cash',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['wallet_unit']]]);
        $this->postJson('/api/v1/consumer/checkouts', ['offer' => $this->offer->slug])->assertConflict();
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->consumerWallet($this->buyer, WalletUnit::Points), 250, 'points-fixture');
        $c = app(CheckoutService::class)->start($this->buyer, $this->offer, 'points-private', unit: WalletUnit::Points);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/consumer/checkouts/'.$c->public_id)->assertNotFound();
        $this->assertSame(1, VoucherCheckout::count());
    }

    public function test_price_version_is_frozen_and_a_stale_offer_cannot_charge_the_old_price(): void
    {
        $wallets = app(WalletService::class);
        $points = $wallets->consumerWallet($this->buyer, WalletUnit::Points);
        $wallets->credit($points, 600, 'points-fixture');
        $service = app(CheckoutService::class);
        $c = $service->start($this->buyer, $this->offer, 'points-frozen', unit: WalletUnit::Points);
        $shop = $this->offer->webshop;
        app(OfferService::class)->update($shop->memberships()->sole(), $this->offer->fresh(), ['price_points' => 500]);
        $this->assertSame(250, $service->start($this->buyer, $this->offer, 'points-frozen', unit: WalletUnit::Points)->price_points);
        try {
            $service->start($this->buyer, $this->offer, 'points-new-price', unit: WalletUnit::Points);
            $this->fail('A stale offer cannot bypass the new price.');
        } catch (ApiException $e) {
            $this->assertSame('checkout_blocked', $e->errorCode);
        }
        $this->assertSame(350, $points->fresh()->available);
        $this->assertSame(250, Voucher::findOrFail($c->voucher_id)->terms_snapshot['price_points']);
    }

    public function test_failed_issuance_rolls_back_points_debit_reservation_and_code(): void
    {
        $wallets = app(WalletService::class);
        $points = $wallets->consumerWallet($this->buyer, WalletUnit::Points);
        $wallets->credit($points, 250, 'points-fixture');
        Event::listen('eloquent.updated: '.Voucher::class, function (Voucher $voucher) {
            if ($voucher->status === VoucherStatus::Issued) {
                throw new \RuntimeException('Synthetic issuance interruption');
            }
        });
        try {
            app(CheckoutService::class)->start($this->buyer, $this->offer, 'points-rollback', unit: WalletUnit::Points);
            $this->fail('The interrupted issuance should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic issuance interruption', $e->getMessage());
        }
        $this->assertSame(250, $points->fresh()->available);
        $this->assertSame(0, $points->fresh()->reserved);
        $this->assertSame(0, VoucherCheckout::count());
        $this->assertSame(0, Voucher::count());
        $this->assertSame(2, VoucherCode::where('status', 'available')->count());
        $this->assertSame(0, WalletLedgerEntry::where('wallet_id', $points->id)->where('type', 'debit')->count());
    }

    public function test_points_need_explicit_pricing_and_cannot_be_disabled_at_checkout(): void
    {
        $service = app(CheckoutService::class);
        $this->assertContains('points_wallet_required', $service->quote($this->buyer, $this->offer, false, WalletUnit::Points)['blockers']);
        app(OfferService::class)->update($this->offer->webshop->memberships()->sole(), $this->offer->fresh(), ['price_points' => null, 'price_minor' => 500]);
        $quote = $service->quote($this->buyer, $this->offer->fresh(), unit: WalletUnit::Points);
        $this->assertContains('not_for_sale', $quote['blockers']);
        $this->assertSame(0, $quote['payment_part_minor']);
    }

    public function test_price_and_unit_are_immutable_and_reconciliation_checks_the_actual_wallet_unit(): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->consumerWallet($this->buyer, WalletUnit::Points), 250, 'points-fixture');
        $c = app(CheckoutService::class)->start($this->buyer, $this->offer, 'points-proof', unit: WalletUnit::Points);
        $euroWallet = $wallets->consumerWallet($this->buyer);
        DB::table('wallet_reservations')->where('id', $c->wallet_reservation_id)->update(['wallet_id' => $euroWallet->id]);
        $this->assertSame('discrepancy', app(FinancialSources::class)->inspect($c)['status']);
        $this->expectException(\LogicException::class);
        $c->forceFill(['price_points' => 1])->save();
    }

    public function test_changed_unit_on_idempotent_replay_is_rejected(): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->consumerWallet($this->buyer, WalletUnit::Points), 250, 'points-fixture');
        $service = app(CheckoutService::class);
        $service->start($this->buyer, $this->offer, 'points-unit-key', unit: WalletUnit::Points);
        $this->expectException(ApiException::class);
        $service->start($this->buyer, $this->offer, 'points-unit-key', unit: WalletUnit::EurCredit);
    }
}
