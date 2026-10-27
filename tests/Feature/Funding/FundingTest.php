<?php

namespace Tests\Feature\Funding;

use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Exceptions\ApiException;
use App\Models\FundingInvoice;
use App\Models\FundingTopUp;
use App\Models\Offer;
use App\Models\SponsorshipObligation;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\Wallet;
use App\Models\Webshop;
use App\Services\Funding\MerchantFunding;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B12: merchant funding of sponsored rewards (Trello #24 E2E 7, #7 E2E 8).
 */
class FundingTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private string $mollieStatus = 'open';

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Mode & Zo']);
        config(['shopbonus.payments.mollie.key' => 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM']);
        Http::fake(['api.mollie.com/v2/*' => function (HttpRequest $r) {
            if ($r->method() === 'POST') {
                return Http::response(['id' => 'tr_topup0001', 'status' => 'open', 'amount' => $r['amount'], 'metadata' => $r['metadata'],
                    '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/topup0001']]], 201);
            }
            $t = FundingTopUp::where('provider_ref', 'tr_topup0001')->first();

            return Http::response(['id' => 'tr_topup0001', 'status' => $this->mollieStatus, 'amount' => ['currency' => 'EUR', 'value' => sprintf('%d.%02d', intdiv($t->amount_minor, 100), $t->amount_minor % 100)],
                'metadata' => ['top_up_id' => $t->public_id]]);
        }]);
    }

    private function funding(): Wallet
    {
        return app(MerchantFunding::class)->wallet($this->shop)->fresh();
    }

    private function drain(): void
    {
        $w = $this->funding();
        app(WalletService::class)->adjust($w, -$w->available, 'drain-'.uniqid(), 'Test: empty the funding wallet', $this->owner->id);
    }

    private function offer(array $terms, int $codes = 2): Offer
    {
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($this->shop->memberships()->where('role', 'owner')->sole()->setRelation('webshop', $this->shop), OfferAcquisition::MerchantReward,
            ['name' => 'Reward', 'redemption_validity_days' => 30, ...$terms]), OfferStatus::Active);
        for ($i = 1; $i <= $codes; $i++) {
            $code = 'RW-'.$offer->id.'-'.$i;
            VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $offer->id, 'code' => $code, 'code_hash' => VoucherCode::hashFor($this->shop->id, $code), 'code_last4' => substr($code, -4)]);
        }

        return $offer;
    }

    public function test_card24_e2e7_fashion_and_electronics_fees_are_booked_once(): void
    {
        $before = $this->funding()->available;
        $inventory = app(VoucherInventory::class);

        // Fashion: €100 order, 10% → €10 reward (fixed) + €3 fee = €13.
        $fashion = $this->offer(['discount_type' => 'fixed', 'discount_value' => 1000]);
        $v = $inventory->reserve($fashion, VoucherOrigin::MerchantReward, 'order:1:offer:'.$fashion->id);
        $inventory->reserve($fashion, VoucherOrigin::MerchantReward, 'order:1:offer:'.$fashion->id); // replay
        $o = SponsorshipObligation::where('voucher_id', $v->id)->sole();
        $this->assertSame([1000, 300, 1300, 'reserved', 'fixed'], [$o->reward_minor, $o->fee_minor, $o->total_minor, $o->status, $o->basis]);
        $this->assertSame([$before - 1300, 1300], [$this->funding()->available, $this->funding()->reserved]);

        // Redemption settles exactly once.
        $consumer = User::factory()->create();
        $inventory->issue($v, $consumer);
        $inventory->redeemByMerchant($v->fresh(), $this->owner->id, 'Order 2');
        $this->assertSame(['settled', 1300, 0], [$o->fresh()->status, $this->funding()->total_spent, $this->funding()->reserved]);

        // Electronics: 4% with max €4 → €4 + €1.20 = €5.20 (maximum-discount basis).
        $electronics = $this->offer(['discount_type' => 'percentage', 'discount_value' => 400, 'max_discount_minor' => 400]);
        $e = $inventory->reserve($electronics, VoucherOrigin::Answer24Claim, 'answer24-handoff:x');
        $this->assertSame([400, 120, 520, 'maximum_discount'], [SponsorshipObligation::where('voucher_id', $e->id)->value('reward_minor'),
            SponsorshipObligation::where('voucher_id', $e->id)->value('fee_minor'), SponsorshipObligation::where('voucher_id', $e->id)->value('total_minor'),
            SponsorshipObligation::where('voucher_id', $e->id)->value('basis')]);
        $this->assertSame(2, SponsorshipObligation::count());
        $this->assertSame([], app(WalletService::class)->reconcile());
    }

    public function test_card7_e2e8_an_empty_funding_wallet_blocks_new_rewards_and_activation(): void
    {
        $offer = $this->offer(['discount_type' => 'fixed', 'discount_value' => 1000]);
        $this->drain();

        try {
            app(VoucherInventory::class)->reserve($offer, VoucherOrigin::MerchantReward, 'order:9:offer:'.$offer->id);
            $this->fail('Unfunded reward was reserved');
        } catch (ApiException $e) {
            $this->assertSame(['merchant_funding_insufficient', 1300], [$e->errorCode, $e->details['required_minor']]);
        }
        $this->assertSame(0, Voucher::count());
        $this->assertSame(2, VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->count());

        $draft = app(OfferService::class)->create($this->shop->memberships()->where('role', 'owner')->sole()->setRelation('webshop', $this->shop), OfferAcquisition::MerchantReward,
            ['name' => 'New', 'discount_type' => 'fixed', 'discount_value' => 500, 'redemption_validity_days' => 30]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/offers/{$draft->public_id}/activate")->assertStatus(409)
            ->assertJsonPath('error.code', 'funding_insufficient')->assertJsonPath('error.details.required_minor', 650);

        // Wallet purchases are paid by the consumer and need no merchant funding.
        $paid = app(OfferService::class)->create($this->shop->memberships()->where('role', 'owner')->sole()->setRelation('webshop', $this->shop), OfferAcquisition::WalletPurchase,
            ['name' => 'Paid', 'discount_type' => 'fixed', 'discount_value' => 2500, 'price_minor' => 2000, 'redemption_validity_days' => 30]);
        $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/offers/{$paid->public_id}/activate")->assertOk();
    }

    public function test_released_and_expired_vouchers_give_the_funding_back(): void
    {
        $offer = $this->offer(['discount_type' => 'fixed', 'discount_value' => 1000]);
        $before = $this->funding()->available;
        $inventory = app(VoucherInventory::class);

        $released = $inventory->reserve($offer, VoucherOrigin::MerchantReward, 'a');
        $inventory->release($released, 'claim_expired');
        $this->assertSame(['released', $before], [SponsorshipObligation::where('voucher_id', $released->id)->value('status'), $this->funding()->available]);

        $expiring = $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'b'), User::factory()->create());
        $this->travel(31)->days();
        $this->artisan('shopbonus:expire-vouchers')->assertSuccessful();
        $this->assertSame(['released', $before, 0], [SponsorshipObligation::where('voucher_id', $expiring->id)->value('status'), $this->funding()->available, $this->funding()->total_spent]);
    }

    public function test_top_up_via_mollie_credits_once_and_only_finance_managers_may_start_it(): void
    {
        $this->drain();
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/workspaces/{$this->shop->public_id}/funding/top-ups";

        $this->postJson($url, ['amount_minor' => 500])->assertUnprocessable(); // below the minimum
        $id = $this->postJson($url, ['amount_minor' => 50000])->assertCreated()->assertJsonPath('data.state', 'pending')
            ->assertJsonPath('data.checkout_url', 'https://www.mollie.com/checkout/select-method/topup0001')->json('data.id');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r['method'] === 'ideal' && $r['amount']['value'] === '500.00');

        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/payments/mollie/webhook', ['id' => 'tr_topup0001'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(0, $this->funding()->available); // still open

        $this->mollieStatus = 'paid';
        $this->post('/api/v1/payments/mollie/webhook', ['id' => 'tr_topup0001'], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/v1/payments/mollie/webhook', ['id' => 'tr_topup0001'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(50000, $this->funding()->available);

        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/workspaces/{$this->shop->public_id}/funding/top-ups/{$id}")->assertJsonPath('data.state', 'paid')->assertJsonPath('data.checkout_url', null);
        $this->getJson("/api/v1/workspaces/{$this->shop->public_id}/funding")->assertOk()->assertJsonPath('data.available_minor', 50000)
            ->assertJsonPath('data.topped_up_minor', 50000)->assertJsonPath('data.withdrawable', false)->assertJsonPath('data.fee_rate_bps', 3000);

        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => 'active', 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->postJson($url, ['amount_minor' => 50000])->assertForbidden();
        $this->getJson("/api/v1/workspaces/{$this->shop->public_id}/funding")->assertForbidden();

        $other = $this->activeWebshop($otherOwner = User::factory()->create());
        Sanctum::actingAs($otherOwner);
        $this->getJson("/api/v1/workspaces/{$other->public_id}/funding/top-ups/{$id}")->assertNotFound();
    }

    public function test_top_up_key_replays_and_conflicting_amount_never_creates_another_payment(): void
    {
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/workspaces/{$this->shop->public_id}/funding/top-ups";
        $key = (string) Str::uuid();
        $id = $this->withHeader('Idempotency-Key', strtoupper($key))->postJson($url, ['amount_minor' => 1000])
            ->assertCreated()->assertJsonPath('data.creation_state', 'created')->json('data.id');
        config(['shopbonus.payments.mollie.key' => '']);
        $this->withHeader('Idempotency-Key', $key)->postJson($url, ['amount_minor' => 1000])
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($url, ['amount_minor' => 2000])->assertConflict()->assertJsonPath('error.code', 'funding_request_conflict');
        Http::assertSentCount(1);
        $topUp = FundingTopUp::sole();
        $this->assertSame($key, $topUp->request_key);
        $this->assertNotSame($key, $topUp->provider_idempotency_key);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Idempotency-Key', $topUp->provider_idempotency_key));
        $this->withHeader('Idempotency-Key', 'invalid')->postJson($url, ['amount_minor' => 1000])->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_the_same_key_is_independent_in_another_workspace_and_identity_is_immutable(): void
    {
        Http::swap(new Factory);
        $number = 0;
        Http::fake(function () use (&$number) {
            return Http::response(['id' => 'tr_scoped'.(++$number), 'status' => 'open'], 201);
        });
        $key = (string) Str::uuid();
        $service = app(MerchantFunding::class);
        $first = $service->startTopUp($this->shop, 1000, $this->owner, $key);
        $otherOwner = User::factory()->create();
        $other = $this->activeWebshop($otherOwner);
        $second = $service->startTopUp($other, 1000, $otherOwner, $key);
        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertNotSame($first->provider_idempotency_key, $second->provider_idempotency_key);
        Http::assertSentCount(2);
        $this->expectException(\LogicException::class);
        $first->forceFill(['amount_minor' => 2000])->save();
    }

    public function test_uncertain_creation_is_quarantined_even_after_provider_cache_expiry(): void
    {
        // Replace the factory registered in setUp, rather than stacking fakes.
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        Sanctum::actingAs($this->owner);
        $url = "/api/v1/workspaces/{$this->shop->public_id}/funding/top-ups";
        $this->drain();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson($url, ['amount_minor' => 1000])
            ->assertStatus(502)->assertJsonPath('error.code', 'funding_creation_uncertain');
        $topUp = FundingTopUp::sole();
        $this->travel(2)->hours();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->postJson($url, ['amount_minor' => 1000])->assertCreated()
            ->assertJsonPath('data.id', $topUp->public_id)->assertJsonPath('data.creation_state', 'uncertain')->assertJsonPath('data.checkout_url', null);
        Http::assertNothingSent();
        $this->assertNull($topUp->provider_ref);
        $this->assertSame(0, $this->funding()->available);
        $this->assertSame(1, FundingTopUp::count());
    }

    public function test_monthly_invoice_draft_totals_settled_rewards_and_fees(): void
    {
        $offer = $this->offer(['discount_type' => 'fixed', 'discount_value' => 1000], codes: 3);
        $inventory = app(VoucherInventory::class);
        foreach (['x', 'y'] as $key) {
            $v = $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, $key), User::factory()->create());
            $inventory->redeemByMerchant($v, $this->owner->id, null);
        }
        $inventory->reserve($offer, VoucherOrigin::MerchantReward, 'z'); // reserved, not settled: not invoiced

        $period = now()->format('Y-m');
        $this->artisan('shopbonus:prepare-invoices', ['period' => $period])->assertSuccessful();
        $this->artisan('shopbonus:prepare-invoices', ['period' => $period])->assertSuccessful(); // rerun: same draft

        $invoice = FundingInvoice::sole();
        $this->assertSame([2000, 600, 2, 'draft'], [$invoice->reward_minor, $invoice->fee_minor, $invoice->lines, $invoice->status]);
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/v1/workspaces/{$this->shop->public_id}/invoices")->assertOk()->assertJsonPath('data.0.total_minor', 2600)->assertJsonPath('data.0.moneybird', 'not_connected');
        $this->getJson("/api/v1/workspaces/{$this->shop->public_id}/funding/obligations?status=settled")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_sponsorships_keep_their_original_on_top_fee_rate(): void
    {
        $inventory = app(VoucherInventory::class);
        config(['shopbonus.fees.direct_reward_fee_bps' => 3000]);
        $first = $inventory->reserve($this->offer(['discount_type' => 'fixed', 'discount_value' => 1000]), VoucherOrigin::MerchantReward, 'historic-first');
        config(['shopbonus.fees.direct_reward_fee_bps' => 1000]);
        $second = $inventory->reserve($this->offer(['discount_type' => 'fixed', 'discount_value' => 1000]), VoucherOrigin::MerchantReward, 'historic-second');
        $old = SponsorshipObligation::where('voucher_id', $first->id)->sole();
        $new = SponsorshipObligation::where('voucher_id', $second->id)->sole();
        $this->assertSame([3000, 300, 1300], [$old->fee_rate_bps, $old->fee_minor, $old->total_minor]);
        $this->assertSame([1000, 100, 1100], [$new->fee_rate_bps, $new->fee_minor, $new->total_minor]);
        $this->expectException(\LogicException::class);
        $old->forceFill(['fee_minor' => 100])->save();
    }
}
