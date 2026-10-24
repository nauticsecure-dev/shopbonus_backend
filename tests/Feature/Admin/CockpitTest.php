<?php

namespace Tests\Feature\Admin;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherOrigin;
use App\Enums\WebshopStatus;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B16b: cockpit KPIs from real records (Trello #21 E2E 1).
 */
class CockpitTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private User $owner;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Europe/Amsterdam'));
        $this->admin = User::factory()->create(['onboarding_intent' => 'merchant']);
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->owner = User::factory()->create(['onboarding_intent' => 'merchant']);
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
    }

    private function offerWithCodes(int $codes): Offer
    {
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), OfferAcquisition::MerchantReward,
            ['name' => 'Korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 30]), OfferStatus::Active);
        for ($i = 1; $i <= $codes; $i++) {
            VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $offer->id, 'code' => "C{$i}", 'code_hash' => VoucherCode::hashFor($this->shop->id, "C{$i}"), 'code_last4' => "C{$i}"]);
        }

        return $offer;
    }

    public function test_card21_e2e1_kpis_trends_and_attention_come_from_real_records(): void
    {
        // Webshops: 1 approved (setUp), 1 pending review, 1 suspended.
        Webshop::factory()->create(['status' => WebshopStatus::PendingReview, 'name' => 'Nieuw', 'review_requested_at' => now()->subHour(), 'created_by' => $this->owner->id]);
        Webshop::factory()->create(['status' => WebshopStatus::Suspended, 'created_by' => $this->owner->id]);

        // Consumers: 3 registered, one signed in this week, one created 60 days ago.
        $anna = User::factory()->create(['onboarding_intent' => 'consumer', 'last_login_at' => now()->subDays(2)]);
        User::factory()->create(['onboarding_intent' => 'consumer']);
        User::factory()->create(['onboarding_intent' => 'consumer', 'created_at' => now()->subDays(60)]);

        // Offer with 4 codes: one issued+redeemed, one issued; 2 left.
        $offer = $this->offerWithCodes(4);
        $inventory = app(VoucherInventory::class);
        $v1 = $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'o1'), $anna);
        $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'o2'), $anna);
        $inventory->redeemByMerchant($v1, $this->owner->id, null);

        // Orders: 2 received, 1 confirmed paid.
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $connection = WebshopConnection::where('webshop_id', $this->shop->id)->sole();
        $base = ['webshop_id' => $this->shop->id, 'connection_id' => $connection->id, 'platform' => 'prestashop', 'ingestion_status' => 'complete', 'currency' => 'EUR', 'placed_at' => now()];
        WebshopOrder::create($base + ['external_order_id' => 'A', 'amount_minor' => 10000, 'payment_status' => 'paid', 'paid_at' => now()]);
        WebshopOrder::create($base + ['external_order_id' => 'B', 'amount_minor' => 5000, 'payment_status' => 'pending']);
        $connection->forceFill(['status' => 'error', 'last_error_code' => 'auth_failed', 'last_error_at' => now()])->save();

        // Affiliate: pending and approved are reported separately, never summed.
        $net = DB::table('affiliate_network_connections')->insertGetId(['public_id' => strtolower((string) str()->ulid()), 'network' => 'daisycon', 'name' => 'D', 'status' => 'connected',
            'credentials' => 'x', 'postback_token_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['pending', 700], ['pending', 300], ['approved', 2500], ['rejected', 100]] as $i => [$status, $commission]) {
            DB::table('affiliate_transactions')->insert(['public_id' => strtolower((string) str()->ulid()), 'network' => 'daisycon', 'connection_id' => $net, 'external_id' => "T{$i}",
                'order_amount_minor' => 10000, 'currency' => 'EUR', 'commission_minor' => $commission, 'network_status' => $status, 'status' => $status,
                'transaction_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        // Wallet: 1500 credited, 400 spent → 1100 outstanding.
        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet($anna);
        $wallets->credit($wallet, 1500, 'test:credit');
        $wallets->debit($wallet->refresh(), 400, 'test:debit');

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data');
        $k = $data['kpis'];

        $this->assertSame(['total' => 3, 'active' => 1, 'pending' => 1, 'pending_review' => 1, 'suspended' => 1, 'rejected' => 0], $k['webshops']);
        $this->assertSame(['registered' => 3, 'new_in_period' => 2, 'active_in_period' => 1], $k['consumers']);
        $this->assertSame(1, $k['offers']['published']);
        $this->assertSame(['issued_in_period' => 2, 'redeemed_in_period' => 1, 'outstanding' => 1, 'available_codes' => 2], $k['vouchers']);
        $this->assertSame(['received_in_period' => 2, 'confirmed_paid_in_period' => 1], $k['orders']);
        $this->assertSame(['count' => 2, 'by_currency' => ['EUR' => 1000]], $k['affiliate_commissions']['pending']);
        $this->assertSame(['count' => 1, 'by_currency' => ['EUR' => 2500]], $k['affiliate_commissions']['approved']);
        $this->assertSame(['issued_in_period' => 1500, 'spent_in_period' => 400, 'expired_in_period' => 0, 'outstanding' => 1100, 'pending' => 0], $k['wallets']['eur_credit']);

        // Daily trend in the business timezone; today has the activity.
        $this->assertCount(7, $data['trends']['days']);
        $today = collect($data['trends']['days'])->firstWhere('date', '2026-10-24');
        $this->assertSame(['paid_orders' => 1, 'vouchers_issued' => 2, 'vouchers_redeemed' => 1], array_intersect_key($today, array_flip(['paid_orders', 'vouchers_issued', 'vouchers_redeemed'])));

        // Attention lists link to the records.
        $this->assertSame('Nieuw', $data['attention']['pending_approvals'][0]['name']);
        $this->assertSame([$connection->public_id, 'auth_failed'], [$data['attention']['integration_failures'][0]['connection_id'], $data['attention']['integration_failures'][0]['error_code']]);
        $this->assertNotEmpty($data['definitions']);
        $this->assertSame(60, $data['cache_seconds']);

        // Custom ranges are bounded; other periods work.
        $this->getJson('/api/v1/admin/cockpit?period=custom&from=2025-01-01&to=2026-10-24')->assertStatus(422)->assertJsonPath('error.code', 'range_too_long');
        $this->getJson('/api/v1/admin/cockpit?period=today')->assertOk()->assertJsonCount(1, 'data.trends.days');
        $this->getJson('/api/v1/admin/cockpit?period=custom&from=2026-10-01')->assertStatus(422);
    }

    public function test_cockpit_is_cached_with_a_freshness_indicator(): void
    {
        Sanctum::actingAs($this->admin);
        $first = $this->getJson('/api/v1/admin/cockpit?period=today')->assertOk()->json('data');
        Webshop::factory()->create(['status' => WebshopStatus::PendingReview, 'created_by' => $this->owner->id]);
        $this->travel(10)->seconds();
        $cached = $this->getJson('/api/v1/admin/cockpit?period=today')->json('data');
        $this->assertSame($first['generated_at'], $cached['generated_at']);
        $this->assertSame($first['kpis']['webshops']['total'], $cached['kpis']['webshops']['total']);

        Cache::flush();
        $this->assertSame($first['kpis']['webshops']['total'] + 1, $this->getJson('/api/v1/admin/cockpit?period=today')->json('data.kpis.webshops.total'));
    }

    public function test_activity_feed_shows_real_actor_and_filters(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withSession(['auth.password_confirmed_at' => time()])->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->postJson("/api/v1/admin/webshops/{$this->shop->public_id}/suspend", ['reason' => 'Fraud check'])->assertOk();

        $feed = $this->getJson('/api/v1/admin/activity?category=admin')->assertOk();
        $this->assertSame('workspace.suspended', $feed->json('data.0.action'));
        $this->assertSame($this->admin->public_id, $feed->json('data.0.actor.id'));
        $this->assertSame('Woon & Co', $feed->json('data.0.webshop.name'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/activity?action=workspace.susp')->json('meta.total'));

        // Admin only, including GET.
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/admin/cockpit')->assertForbidden();
        $this->getJson('/api/v1/admin/activity')->assertForbidden();
    }
}
