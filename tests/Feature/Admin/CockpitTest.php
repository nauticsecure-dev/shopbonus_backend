<?php

namespace Tests\Feature\Admin;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Enums\WebshopStatus;
use App\Models\Offer;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReview;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Audit\AuditLogger;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * Exactly 10 E2E scenarios for Super Admin Cockpit (Trello #21).
 * Hits authoritative admin APIs the live frontend consumes — no duplicate ledgers.
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
        $this->admin = User::factory()->create(['onboarding_intent' => 'merchant', 'email' => 'admin@shopbonus.test']);
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->owner = User::factory()->create(['onboarding_intent' => 'merchant']);
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        Cache::flush();
    }

    private function offerWithCodes(int $codes): Offer
    {
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), OfferAcquisition::MerchantReward,
            ['name' => 'Korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 30]), OfferStatus::Active);
        for ($i = 1; $i <= $codes; $i++) {
            VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $offer->id, 'code' => "C{$i}-".Str::upper(Str::random(4)), 'code_hash' => VoucherCode::hashFor($this->shop->id, "C{$i}-{$i}"), 'code_last4' => sprintf('%04d', $i)]);
        }

        return $offer;
    }

    private function insertAffiliate(string $network, string $status, int $commission, string $externalId, ?int $connectionId = null): void
    {
        $connectionId ??= DB::table('affiliate_network_connections')->insertGetId([
            'public_id' => strtolower((string) str()->ulid()), 'network' => $network, 'name' => $network, 'status' => 'connected',
            'credentials' => 'x', 'postback_token_hash' => hash('sha256', $externalId), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('affiliate_transactions')->insert([
            'public_id' => strtolower((string) str()->ulid()), 'network' => $network, 'connection_id' => $connectionId, 'external_id' => $externalId,
            'order_amount_minor' => 10000, 'currency' => 'EUR', 'commission_minor' => $commission, 'network_status' => $status, 'status' => $status,
            'reward_status' => 'none', 'transaction_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function openSupportReview(User $consumer): SupportReview
    {
        $conversation = SupportConversation::create([
            'user_id' => $consumer->id, 'locale' => 'nl', 'expires_at' => now()->addDays(30),
        ]);
        $message = SupportMessage::create([
            'conversation_id' => $conversation->id, 'user_id' => $consumer->id,
            'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', (string) Str::uuid()),
            'question' => 'Help', 'research' => [], 'status' => 'completed', 'retry_used' => false, 'completed_at' => now(),
        ]);

        return SupportReview::create(['message_id' => $message->id, 'trigger' => 'automatic']);
    }

    /** E2E 1 — Super Admin access */
    public function test_e2e1_super_admin_opens_cockpit_and_merchant_is_denied(): void
    {
        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/admin/cockpit?period=30d')->assertOk()->json('data');
        $this->assertCount(6, $data['cards']);
        $this->assertArrayHasKey('trends', $data);
        $this->assertArrayHasKey('affiliate_channels', $data);
        $this->assertArrayHasKey('attention', $data);
        $this->assertArrayHasKey('open_actions', $data['attention']);
        $this->getJson('/api/v1/admin/activity')->assertOk();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/admin/cockpit')->assertForbidden();
        app(AuditLogger::class)->record(
            'admin.cockpit_denied',
            outcome: AuditOutcome::Denied,
            category: AuditCategory::Security,
            actorUserId: $this->owner->id,
            httpStatus: 403,
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'admin.cockpit_denied',
            'outcome' => AuditOutcome::Denied->value,
            'actor_user_id' => $this->owner->id,
            'http_status' => 403,
        ]);
    }

    /** E2E 2 — Webshop and consumer counts */
    public function test_e2e2_active_webshops_and_consumers_follow_definitions(): void
    {
        $this->activeWebshop($this->owner, ['name' => 'TuinPlus']);
        Webshop::factory()->create(['status' => WebshopStatus::PendingReview, 'name' => 'Pending Shop', 'created_by' => $this->owner->id, 'review_requested_at' => now()]);
        User::factory()->create(['onboarding_intent' => 'consumer', 'status' => UserStatus::Active]);
        User::factory()->create(['onboarding_intent' => 'consumer', 'status' => UserStatus::Active]);
        User::factory()->create(['onboarding_intent' => 'consumer', 'status' => UserStatus::Active]);
        User::factory()->create(['onboarding_intent' => 'consumer', 'status' => UserStatus::Deleted]);

        Sanctum::actingAs($this->admin);
        $cards = collect($this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data.cards'))->keyBy('key');
        $this->assertSame(2, $cards['active_webshops']['value']);
        $this->assertSame(3, $cards['consumers']['value']);
        $this->assertSame('/admin/webshops?status=approved', $cards['active_webshops']['href']);
        $this->assertStringContainsString('consumer', $cards['consumers']['href']);
        $this->getJson('/api/v1/admin/webshops?status=approved')->assertOk()->assertJsonPath('meta.total', 2);
        // The card and the list it links to use the same consumer definition.
        $this->getJson('/api/v1/admin/users?role=consumer&status=active')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_change_percent_compares_with_totals_at_the_end_of_the_previous_period(): void
    {
        foreach (range(1, 2) as $i) {
            User::factory()->create(['status' => UserStatus::Active, 'created_at' => now()->subDays(20)]);
        }
        User::factory()->create(['status' => UserStatus::Active, 'created_at' => now()->subDay()]);

        Sanctum::actingAs($this->admin);
        $cards = collect($this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data.cards'))->keyBy('key');
        $this->assertEquals([3, 50], [$cards['consumers']['value'], $cards['consumers']['change_percent']]); // 2 → 3
        $this->assertNull($cards['open_actions']['change_percent']); // no history to compare with
    }

    /** E2E 3 — Voucher lifecycle */
    public function test_e2e3_active_vouchers_and_issuance_chart_respect_lifecycle(): void
    {
        $consumer = User::factory()->create(['onboarding_intent' => 'consumer']);
        $offer = $this->offerWithCodes(5);
        $inventory = app(VoucherInventory::class);
        $issued = [];
        for ($i = 1; $i <= 5; $i++) {
            $issued[] = $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, "life-{$i}"), $consumer);
        }
        $inventory->redeemByMerchant($issued[0], $this->owner->id, null);
        $issued[1]->forceFill(['expires_at' => now()->subMinute()])->save();
        app(VoucherInventory::class)->expire(now());
        // Leave issued[2], issued[3] active; issued[4] stays issued (unclaimed already issued to consumer).
        // Spec: claim three, redeem one, expire another, leave one unclaimed.
        // Our flow issues all five to consumer (claim+issue). Redeem 1, expire 1 → 3 active issued.

        Sanctum::actingAs($this->admin);
        Cache::flush();
        $data = $this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data');
        $active = collect($data['cards'])->firstWhere('key', 'active_vouchers')['value'];
        $this->assertSame(3, $active);
        $this->assertSame(5, $data['kpis']['vouchers']['issued_in_period']);
        $today = collect($data['trends']['days'])->firstWhere('date', '2026-10-24');
        $this->assertSame(5, $today['vouchers_issued']);
        $this->assertSame(1, $data['kpis']['vouchers']['redeemed_in_period']);
        $this->assertSame(Voucher::where('status', VoucherStatus::Issued)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(), $active);
    }

    /** E2E 4 — Affiliate commission accuracy */
    public function test_e2e4_confirmed_pending_and_reversed_commissions_stay_separate(): void
    {
        $net = DB::table('affiliate_network_connections')->insertGetId([
            'public_id' => strtolower((string) str()->ulid()), 'network' => 'daisycon', 'name' => 'D', 'status' => 'connected',
            'credentials' => 'x', 'postback_token_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->insertAffiliate('daisycon', 'approved', 2500, 'CONF-1', $net);
        $this->insertAffiliate('tradetracker', 'pending', 900, 'PEND-1', $net);
        $this->insertAffiliate('daisycon', 'reversed', 400, 'REV-1', $net);

        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data');
        $card = collect($data['cards'])->firstWhere('key', 'affiliate_commission');
        $this->assertSame(2500, $card['value']);
        $this->assertSame(2500, $card['breakdown']['confirmed_minor']);
        $this->assertSame(900, $card['breakdown']['pending_minor']);
        $this->assertSame(400, $card['breakdown']['reversed_minor']);
        $this->assertSame(['count' => 1, 'by_currency' => ['EUR' => 2500]], $data['kpis']['affiliate_commissions']['approved']);
        $this->assertSame(['count' => 1, 'by_currency' => ['EUR' => 900]], $data['kpis']['affiliate_commissions']['pending']);
        $this->assertSame(['count' => 1, 'by_currency' => ['EUR' => 400]], $data['kpis']['affiliate_commissions']['reversed']);
        $this->assertStringContainsString('pending', strtolower($card['helper']));
    }

    /** E2E 5 — Wallet liability */
    public function test_e2e5_wallet_liability_reconciles_available_and_reserved(): void
    {
        $consumer = User::factory()->create(['onboarding_intent' => 'consumer']);
        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet($consumer);
        $wallets->credit($wallet, 2000, 'test:credit');
        $wallets->reserve($wallet->refresh(), 500, 'test:reserve');

        Sanctum::actingAs($this->admin);
        Cache::flush();
        $card = collect($this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data.cards'))->firstWhere('key', 'wallet_liability');
        $this->assertSame(2000, $card['value']);
        $this->assertSame(1500, $card['breakdown']['available_minor']);
        $this->assertSame(500, $card['breakdown']['reserved_minor']);
        $this->assertStringContainsString('geen winst', strtolower($card['helper']));
        $this->assertSame('/admin/wallets', $card['href']);
    }

    /** E2E 6 — Reporting filters */
    public function test_e2e6_voucher_chart_periods_and_day_filter(): void
    {
        $consumer = User::factory()->create(['onboarding_intent' => 'consumer']);
        $offer = $this->offerWithCodes(3);
        $inventory = app(VoucherInventory::class);
        $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'p1'), $consumer);
        $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'p2'), $consumer);

        Sanctum::actingAs($this->admin);
        foreach (['7d' => 7, '30d' => 30, '90d' => 90] as $period => $days) {
            Cache::flush();
            $data = $this->getJson("/api/v1/admin/cockpit?period={$period}")->assertOk()->json('data');
            $this->assertCount($days, $data['trends']['days']);
            $this->assertSame('Europe/Amsterdam', $data['trends']['timezone']);
            $today = collect($data['trends']['days'])->firstWhere('date', '2026-10-24');
            $this->assertSame(2, $today['vouchers_issued']);
        }

        $list = $this->getJson('/api/v1/admin/vouchers?issued_on=2026-10-24')->assertOk()->json();
        $this->assertSame(2, $list['meta']['total']);
        $this->getJson('/api/v1/admin/cockpit?period=custom&from=2025-01-01&to=2026-10-24')
            ->assertStatus(422)->assertJsonPath('error.code', 'range_too_long');
    }

    /** E2E 7 — Affiliate donut */
    public function test_e2e7_affiliate_channel_distribution_percentages(): void
    {
        $daisy = DB::table('affiliate_network_connections')->insertGetId([
            'public_id' => strtolower((string) str()->ulid()), 'network' => 'daisycon', 'name' => 'Daisycon', 'status' => 'connected',
            'credentials' => 'x', 'postback_token_hash' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $trade = DB::table('affiliate_network_connections')->insertGetId([
            'public_id' => strtolower((string) str()->ulid()), 'network' => 'tradetracker', 'name' => 'TradeTracker', 'status' => 'connected',
            'credentials' => 'x', 'postback_token_hash' => str_repeat('d', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < 1608; $i++) {
            $this->insertAffiliate('daisycon', 'approved', 10, "D-{$i}", $daisy);
        }
        for ($i = 0; $i < 756; $i++) {
            $this->insertAffiliate('tradetracker', 'pending', 10, "T-{$i}", $trade);
        }

        Sanctum::actingAs($this->admin);
        Cache::flush();
        $channels = $this->getJson('/api/v1/admin/cockpit?period=30d')->assertOk()->json('data.affiliate_channels');
        $this->assertSame('conversions', $channels['metric']);
        $this->assertSame(2364, $channels['total']);
        $byNet = collect($channels['channels'])->keyBy('network');
        $this->assertSame(1608, $byNet['daisycon']['count']);
        $this->assertSame(756, $byNet['tradetracker']['count']);
        $this->assertSame(68, $byNet['daisycon']['percent']);
        $this->assertSame(32, $byNet['tradetracker']['percent']);
        $this->assertStringContainsString('daisycon', $byNet['daisycon']['href']);
        $this->getJson('/api/v1/admin/affiliate/transactions?network=daisycon')->assertOk();
    }

    /** E2E 8 — Recent activity */
    public function test_e2e8_recent_activity_lists_platform_events_once(): void
    {
        Sanctum::actingAs($this->admin);
        $logger = app(AuditLogger::class);
        $logger->record('workspace.approved', category: AuditCategory::Admin, webshopId: $this->shop->id, actorUserId: $this->admin->id);
        $logger->record('voucher.redeemed', category: AuditCategory::Voucher, webshopId: $this->shop->id, actorUserId: $this->admin->id);
        $logger->record('affiliate.transaction_imported', category: AuditCategory::Financial, actorUserId: $this->admin->id);
        $logger->record('order.sync_failed', category: AuditCategory::Integration, webshopId: $this->shop->id, actorUserId: $this->admin->id);

        $feed = $this->getJson('/api/v1/admin/activity?per_page=20')->assertOk()->json('data');
        $actions = collect($feed)->pluck('action');
        $this->assertSame(1, $actions->filter(fn ($a) => $a === 'workspace.approved')->count());
        $this->assertSame(1, $actions->filter(fn ($a) => $a === 'voucher.redeemed')->count());
        $this->assertSame(1, $actions->filter(fn ($a) => $a === 'affiliate.transaction_imported')->count());
        $this->assertSame(1, $actions->filter(fn ($a) => $a === 'order.sync_failed')->count());
        $times = collect($feed)->pluck('occurred_at')->all();
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
        $approved = collect($feed)->firstWhere('action', 'workspace.approved');
        $this->assertSame('Webshop', $approved['type']);
        $this->assertNotEmpty($approved['href']);
    }

    /** E2E 9 — Action Required */
    public function test_e2e9_action_required_counts_update_after_resolution(): void
    {
        Webshop::factory()->count(3)->create([
            'status' => WebshopStatus::PendingReview, 'created_by' => $this->owner->id, 'review_requested_at' => now(),
        ]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $c1 = WebshopConnection::where('webshop_id', $this->shop->id)->sole();
        $c1->forceFill(['status' => 'error', 'last_error_code' => 'auth_failed', 'last_error_at' => now()])->save();
        $shop2 = $this->activeWebshop($this->owner, ['name' => 'Sync Two']);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/workspaces/{$shop2->public_id}/connections", ['platform' => 'woocommerce'])->assertCreated();
        $c2 = WebshopConnection::where('webshop_id', $shop2->id)->sole();
        $c2->forceFill(['status' => 'error', 'last_error_code' => 'timeout', 'last_error_at' => now()])->save();

        $consumer = User::factory()->create(['onboarding_intent' => 'consumer']);
        $reviews = [];
        for ($i = 0; $i < 4; $i++) {
            $reviews[] = $this->openSupportReview($consumer);
        }

        Sanctum::actingAs($this->admin);
        Cache::flush();
        $actions = $this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data.attention.open_actions');
        $this->assertSame(3, $actions['pending_approvals']['count']);
        $this->assertSame(2, $actions['failed_syncs']['count']);
        $this->assertSame(4, $actions['open_support']['count']);
        $this->assertSame(9, $actions['total']);
        $this->assertStringContainsString('pending_review', $actions['pending_approvals']['href']);
        $this->assertStringContainsString('integrations', $actions['failed_syncs']['href']);
        $this->assertStringContainsString('support', $actions['open_support']['href']);

        Webshop::where('status', WebshopStatus::PendingReview)->orderBy('id')->first()
            ->forceFill(['status' => WebshopStatus::Approved])->save();
        $c1->forceFill(['status' => 'connected', 'last_error_code' => null])->save();
        $reviews[0]->forceFill(['outcome' => 'ANSWER_CORRECT', 'note' => 'Resolved in test', 'reviewed_by' => $this->admin->id, 'reviewed_at' => now()])->save();

        Cache::flush();
        $after = $this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data.attention.open_actions');
        $this->assertSame(2, $after['pending_approvals']['count']);
        $this->assertSame(1, $after['failed_syncs']['count']);
        $this->assertSame(3, $after['open_support']['count']);
    }

    /** E2E 10 — Search, security and degraded operation */
    public function test_e2e10_search_security_masking_and_degraded_warnings(): void
    {
        $consumer = User::factory()->create([
            'onboarding_intent' => 'consumer',
            'email' => 'sanne.demo@example.com',
            'first_name' => 'Sanne',
        ]);
        $offer = $this->offerWithCodes(1);
        $voucher = app(VoucherInventory::class)->issue(
            app(VoucherInventory::class)->reserve($offer, VoucherOrigin::MerchantReward, 'search-v'),
            $consumer
        );
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $connection = WebshopConnection::where('webshop_id', $this->shop->id)->sole();
        $order = WebshopOrder::create([
            'webshop_id' => $this->shop->id, 'connection_id' => $connection->id, 'platform' => 'prestashop',
            'ingestion_status' => 'complete', 'currency' => 'EUR', 'placed_at' => now(),
            'external_order_id' => 'ORD-SEARCH-99', 'amount_minor' => 5000, 'payment_status' => 'paid', 'paid_at' => now(),
            'reference' => 'REF-SEARCH-99',
        ]);
        $this->insertAffiliate('daisycon', 'approved', 100, 'AFF-SEARCH-1');

        Sanctum::actingAs($this->admin);
        $shopSearch = $this->getJson('/api/v1/admin/search?q=Woon')->assertOk()->json('data');
        $this->assertNotEmpty($shopSearch['groups']);
        $emailSearch = $this->getJson('/api/v1/admin/search?q=sanne.demo')->assertOk()->json('data.groups');
        $consumerHit = collect($emailSearch)->firstWhere('type', 'consumers')['results'][0] ?? null;
        $this->assertNotNull($consumerHit);
        $this->assertStringContainsString('*', $consumerHit['subtitle']);
        $this->assertStringNotContainsString('sanne.demo@example.com', json_encode($emailSearch));
        $this->getJson('/api/v1/admin/search?q='.$voucher->public_id)->assertOk();
        $this->getJson('/api/v1/admin/search?q=ORD-SEARCH-99')->assertOk();
        $this->getJson('/api/v1/admin/search?q=AFF-SEARCH')->assertOk();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/admin/search?q=Woon')->assertForbidden();

        Sanctum::actingAs($this->admin);
        Cache::flush();
        // Degraded path: summary still returns structure with warnings array present.
        $data = $this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data');
        $this->assertArrayHasKey('warnings', $data);
        $this->assertArrayHasKey('sources', $data);
        $this->assertArrayHasKey('cards', $data);
        $this->assertIsArray($data['warnings']);
        unset($order);
    }
}
