<?php

namespace Tests\Feature\Benefits;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Events\WalletBalanceChanged;
use App\Models\AffiliateClick;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\MerchantCommissionAgreement;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Affiliate\AffiliateSync;
use App\Services\Cashback\DirectMerchantCashback;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * Ten end-to-end acceptance scenarios for Mijn voordelen.
 */
class MyBenefitsE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private User $anna;

    private User $owner;

    private Webshop $shop;

    private AffiliateConnection $daisycon;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->anna = User::factory()->create(['email' => 'anna@example.nl', 'email_verified_at' => now()]);
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Bol.com', 'verified_domain' => 'bol.com']);

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/v1/workspaces/{$this->shop->public_id}/public-profile", [
            'short_description' => 'Bol', 'website_url' => 'https://bol.com', 'categories' => ['electronics'], 'published' => true,
        ])->assertOk();
        $this->app['auth']->forgetGuards();

        $offers = app(OfferService::class);
        $this->offer = $offers->transition($offers->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), OfferAcquisition::WalletPurchase, [
            'name' => '10% korting', 'discount_type' => 'percentage', 'discount_value' => 1000,
            'min_order_minor' => 10000, 'max_discount_minor' => 1000, 'price_minor' => 1000, 'redemption_validity_days' => 30,
        ]), OfferStatus::Active);

        $this->daisycon = AffiliateConnection::create([
            'network' => 'daisycon', 'name' => 'Daisycon', 'status' => 'connected',
            'credentials' => ['publisher_id' => '123', 'access_token' => 'tok', 'media_id' => '9'],
            'postback_token_hash' => AffiliateConnection::hashToken('daisycon-token'),
        ]);
        AffiliateRewardRule::create([
            'version' => 1, 'unit' => 'eur_credit', 'share_bps' => 7000, 'min_commission_minor' => 0,
            'active' => true, 'created_by' => $this->admin->id, 'cashback_approved' => true,
        ]);
    }

    public function test_01_wallet_dashboard_available_earned_spent_and_navbar_match(): void
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet($this->anna);
        $wallets->credit($wallet, 3500, 'earn', meta: ['source_type' => 'affiliate', 'reason' => 'seed earn']);
        $wallets->debit($wallet, 2280, 'spend', ['source_type' => 'voucher_purchase', 'source_ref' => 'v1']);

        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/me/wallet')->assertOk()
            ->assertJsonPath('data.available_minor', 1220)
            ->assertJsonPath('data.total_earned_minor', 3500)
            ->assertJsonPath('data.total_spent_minor', 2280)
            ->assertJsonPath('data.unit', 'EUR_REDEMPTION_CREDIT');
        $this->getJson('/api/v1/consumer/wallet')->assertOk()
            ->assertJsonPath('data.available_minor', 1220)
            ->assertJsonPath('data.balances.eur_credit.pending', 0)
            ->assertJsonPath('data.savings_goal', null);
    }

    public function test_02_standard_affiliate_cashback_immediate_70_30(): void
    {
        $this->seedProgram();
        $ref = $this->click();
        app(AffiliateSync::class)->import($this->daisycon, [], [[
            'affiliatemarketing_id' => 'E2E-2', 'program_id' => 'P1', 'date' => now()->toIso8601String(),
            'last_modified' => now()->toIso8601String(),
            'parts' => [['subid' => $ref, 'referencenumber' => 'ORD-100', 'revenue' => 100.00, 'commission' => 10.00, 'status' => 'approved', 'currency_code' => 'EUR']],
        ]], $this->admin->id);

        $tx = AffiliateTransaction::where('external_id', 'E2E-2')->sole();
        $this->assertSame([1000, 700, 'credited'], [$tx->commission_minor, $tx->reward_amount, $tx->reward_status]);
        $this->assertSame(700, app(WalletService::class)->consumerWallet($this->anna)->fresh()->available);
        $this->assertSame(300, $tx->credit_evidence['allocation']['platform_unrecognized_minor']);
        $this->assertSame(0, app(WalletService::class)->consumerWallet($this->anna)->fresh()->pending);
    }

    public function test_03_custom_direct_merchant_12_percent_with_90_10_split(): void
    {
        MerchantCommissionAgreement::create([
            'webshop_id' => $this->shop->id, 'version' => 1, 'commission_type' => 'percentage',
            'commission_rate_bps' => 1200, 'consumer_share_bps' => 9000, 'shopbonus_share_bps' => 1000,
            'cashback_approved' => true, 'active' => true, 'created_by' => $this->admin->id,
        ]);
        $this->assertSame(0.108, MerchantCommissionAgreement::current($this->shop->id)->consumerCashbackRate());

        $row = app(DirectMerchantCashback::class)->creditVerifiedConversion($this->shop, $this->anna, (string) Str::uuid(), [
            'external_id' => 'DM-1', 'order_amount_minor' => 10_000,
        ]);
        $this->assertSame(['credited', 1200, 1080, 120], [$row->status, $row->commission_minor, $row->consumer_credit_minor, $row->shopbonus_revenue_minor]);
        $this->assertSame(1080, app(WalletService::class)->consumerWallet($this->anna)->fresh()->available);
    }

    public function test_04_click_alone_never_credits_wallet(): void
    {
        $this->seedProgram();
        $this->click();
        $wallet = app(WalletService::class)->consumerWallet($this->anna)->fresh();
        $this->assertSame(0, $wallet->available);
        $this->assertSame(0, WalletLedgerEntry::where('wallet_id', $wallet->id)->count());
        $this->assertDatabaseCount('affiliate_transactions', 0);
    }

    public function test_05_duplicate_conversion_credits_exactly_once(): void
    {
        $this->seedProgram();
        $ref = $this->click();
        $payload = [[
            'affiliatemarketing_id' => 'DUP-1', 'program_id' => 'P1', 'date' => now()->toIso8601String(),
            'last_modified' => now()->toIso8601String(),
            'parts' => [['subid' => $ref, 'referencenumber' => 'ORD-DUP', 'revenue' => 100.00, 'commission' => 10.00, 'status' => 'approved', 'currency_code' => 'EUR']],
        ]];
        $sync = app(AffiliateSync::class);
        $sync->import($this->daisycon, [], $payload, $this->admin->id);
        $sync->import($this->daisycon, [], $payload, $this->admin->id);
        $sync->import($this->daisycon, [], $payload, $this->admin->id);

        $this->assertSame(1, AffiliateTransaction::where('external_id', 'DUP-1')->count());
        $this->assertSame(1, WalletLedgerEntry::where('source_type', 'affiliate')->where('type', 'credit')->count());
        $this->assertSame(700, app(WalletService::class)->consumerWallet($this->anna)->fresh()->available);
    }

    public function test_06_voucher_list_statuses_and_copy_permissions(): void
    {
        $active = $this->issueVoucher(VoucherStatus::Issued);
        $used = $this->issueVoucher(VoucherStatus::Redeemed);
        $expired = $this->issueVoucher(VoucherStatus::Expired);

        Sanctum::actingAs($this->anna);
        $list = collect($this->getJson('/api/v1/consumer/vouchers')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('ACTIVE', $list[$active->public_id]['status']);
        $this->assertTrue($list[$active->public_id]['can_copy_code']);
        $this->assertSame('USED', $list[$used->public_id]['status']);
        $this->assertFalse($list[$used->public_id]['can_copy_code']);
        $this->assertSame('EXPIRED', $list[$expired->public_id]['status']);
        $this->assertFalse($list[$expired->public_id]['can_copy_code']);

        $this->getJson('/api/v1/consumer/vouchers/'.$active->public_id)->assertOk()->assertJsonPath('data.can_copy_code', true);
        $this->getJson('/api/v1/consumer/vouchers/'.$expired->public_id)->assertOk()
            ->assertJsonPath('data.can_copy_code', false)
            ->assertJsonPath('data.code', null);
    }

    public function test_07_voucher_redemption_debits_wallet_atomically(): void
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet($this->anna);
        $wallets->credit($wallet, 1220, 'seed', meta: ['source_type' => 'affiliate']);
        $wallets->debit($wallet, 1000, 'voucher-e2e-7', ['source_type' => 'voucher_purchase', 'source_ref' => 'checkout-7']);
        $this->issueVoucher(VoucherStatus::Issued);

        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/me/wallet')->assertJsonPath('data.available_minor', 220)->assertJsonPath('data.total_spent_minor', 1000);
        $this->assertSame(1, WalletLedgerEntry::where('type', 'debit')->count());
        $this->assertSame(1, Voucher::where('consumer_user_id', $this->anna->id)->where('status', VoucherStatus::Issued)->count());
    }

    public function test_08_ledger_and_auditable_reversal(): void
    {
        $this->seedProgram();
        $ref = $this->click();
        app(AffiliateSync::class)->import($this->daisycon, [], [[
            'affiliatemarketing_id' => 'REV-1', 'program_id' => 'P1', 'date' => now()->toIso8601String(),
            'last_modified' => now()->toIso8601String(),
            'parts' => [['subid' => $ref, 'referencenumber' => 'ORD-REV', 'revenue' => 100.00, 'commission' => 10.00, 'status' => 'approved', 'currency_code' => 'EUR']],
        ]], $this->admin->id);

        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet($this->anna);
        $wallets->debit($wallet, 500, 'voucher-rev', ['source_type' => 'voucher_purchase']);

        $tx = AffiliateTransaction::where('external_id', 'REV-1')->sole();
        $credit = WalletLedgerEntry::findOrFail($tx->wallet_entry_id);
        $countBefore = WalletLedgerEntry::count();
        $wallets->reverse($credit, 'affiliate:reverse:'.$tx->public_id, 'network_reversed', $this->admin->id);

        Sanctum::actingAs($this->anna);
        $this->getJson('/api/v1/consumer/wallet/transactions?filter=adjustments')->assertOk();
        $this->assertGreaterThan($countBefore, WalletLedgerEntry::count());
        $this->assertSame($credit->amount, $credit->fresh()->amount);
        $this->assertDatabaseHas('wallet_ledger_entries', ['type' => 'reversal', 'related_entry_id' => $credit->id]);
    }

    public function test_09_ai_voucher_support_receives_authorized_reference(): void
    {
        $voucher = $this->issueVoucher(VoucherStatus::Issued);
        Sanctum::actingAs($this->anna);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/ai/chat', [
                'message' => 'Probleem met mijn vouchercode',
                'locale' => 'nl',
                'reference' => $voucher->public_id,
                'current_page' => '/mijn-voordelen',
            ])->assertStatus(202)
            ->assertJsonStructure(['data' => ['id', 'conversation_id', 'status']]);

        Sanctum::actingAs(User::factory()->create(['email_verified_at' => now()]));
        $this->getJson('/api/v1/consumer/vouchers/'.$voucher->public_id)->assertNotFound();
    }

    public function test_10_reverb_wallet_event_and_account_isolation(): void
    {
        $event = new WalletBalanceChanged($this->anna->public_id, 'credit');
        $this->assertSame('private-shopbonus.user.'.$this->anna->public_id.'.wallet', $event->broadcastOn()[0]->name);
        $this->assertSame('WalletBalanceChanged', $event->broadcastAs());
        $this->assertSame(['reason' => 'credit'], $event->broadcastWith());

        $wallets = app(WalletService::class);
        $wallets->credit($wallets->consumerWallet($this->anna), 500, 'live-credit', meta: ['source_type' => 'affiliate']);

        $other = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/me/wallet')->assertJsonPath('data.available_minor', 0);
        $this->assertSame([], $this->getJson('/api/v1/consumer/wallet/transactions')->json('data'));

        $callbacks = Broadcast::getChannels();
        $this->assertArrayHasKey('shopbonus.user.{userId}.wallet', $callbacks);
        $this->assertTrue($callbacks['shopbonus.user.{userId}.wallet']($this->anna, $this->anna->public_id));
        $this->assertFalse($callbacks['shopbonus.user.{userId}.wallet']($other, $this->anna->public_id));
    }

    private function seedProgram(): void
    {
        AffiliateProgram::create([
            'connection_id' => $this->daisycon->id, 'external_id' => 'P1', 'name' => 'Bol.com',
            'domain' => 'bol.com', 'webshop_id' => $this->shop->id, 'active' => true,
            'commission_type' => 'percentage', 'commission_value' => 1000, 'currency' => 'EUR',
            'category_restricted' => false, 'cashback_allowed' => true,
            'tracking_url_template' => 'https://lt45.net/c/?r={click_ref}',
        ]);
    }

    private function click(): string
    {
        Sanctum::actingAs($this->anna);
        $slug = $this->shop->publicProfile()->value('slug');
        $this->get('/api/v1/public/webshops/'.$slug.'/visit')->assertRedirect();
        $this->app['auth']->forgetGuards();

        return AffiliateClick::latest('id')->firstOrFail()->reference;
    }

    private function issueVoucher(VoucherStatus $status): Voucher
    {
        $code = 'CODE'.strtoupper(Str::random(4));
        VoucherCode::create([
            'webshop_id' => $this->shop->id, 'offer_id' => $this->offer->id, 'code' => $code,
            'code_hash' => VoucherCode::hashFor($this->shop->id, $code), 'code_last4' => substr($code, -4),
        ]);
        $inventory = app(VoucherInventory::class);
        $voucher = $inventory->issue($inventory->reserve($this->offer, VoucherOrigin::Promotional, 'test:'.uniqid()), $this->anna);

        if ($status === VoucherStatus::Redeemed) {
            $voucher->forceFill(['status' => VoucherStatus::Redeemed, 'redeemed_at' => now()->subHour()])->save();
        }
        if ($status === VoucherStatus::Expired) {
            $voucher->forceFill(['status' => VoucherStatus::Expired, 'expires_at' => now()->subDay()])->save();
        }

        return $voucher->fresh('code');
    }
}
