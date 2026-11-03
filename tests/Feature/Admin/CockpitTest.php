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
use App\Enums\WalletUnit;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\AffiliateTransaction;
use App\Models\AuditEvent;
use App\Models\ContactOpportunity;
use App\Models\Offer;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReview;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\VoucherDispute;
use App\Models\VoucherEvent;
use App\Models\WebhookEvent;
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
 * API integration regression scenarios for Super Admin Cockpit (Trello #21).
 * Exercises the admin APIs; browser/provider verification is separate — no duplicate ledgers.
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

    private function offerWithCodes(int $codes, ?Webshop $shop = null): Offer
    {
        $shop ??= $this->shop;
        $offers = app(OfferService::class);
        $offer = $offers->transition($offers->create($shop->memberships()->sole()->setRelation('webshop', $shop), OfferAcquisition::MerchantReward,
            ['name' => 'Korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 30]), OfferStatus::Active);
        for ($i = 1; $i <= $codes; $i++) {
            $value = "C{$i}-".Str::upper(Str::random(4));
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $value, 'code_hash' => VoucherCode::hashFor($shop->id, $value), 'code_last4' => substr($value, -4)]);
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

    public function test_consumer_trend_uses_account_intent_and_exclusions_independent_of_membership(): void
    {
        $member = User::factory()->create(['onboarding_intent' => 'consumer']);
        $this->activeWebshop($member);
        User::factory()->create(['onboarding_intent' => 'merchant']);
        $test = User::factory()->create(['onboarding_intent' => 'consumer']);
        config(['shopbonus.admin.test_account_public_ids' => [$test->public_id]]);
        User::factory()->create(['onboarding_intent' => 'consumer', 'created_at' => now()->subDays(40)]);
        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data');
        $this->assertSame(1, collect($data['trends']['days'])->sum('new_consumers'));
        $this->assertSame(1, collect($data['trends']['days'])->firstWhere('date', '2026-10-24')['new_consumers']);
        $this->assertSame(2, $data['kpis']['consumers']['registered']);
    }

    public function test_current_consumer_total_has_no_invented_historical_change(): void
    {
        foreach (range(1, 2) as $i) {
            User::factory()->create(['status' => UserStatus::Active, 'created_at' => now()->subDays(20)]);
        }
        User::factory()->create(['status' => UserStatus::Active, 'created_at' => now()->subDay()]);

        Sanctum::actingAs($this->admin);
        $cards = collect($this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data.cards'))->keyBy('key');
        $this->assertEquals([3, null], [$cards['consumers']['value'], $cards['consumers']['change_percent']]);
        $this->assertStringContainsString('Historical snapshots', $cards['consumers']['comparison_unavailable_reason']);
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
        foreach (['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90] as $period => $days) {
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
        $consumer = User::factory()->create();
        $offer = $this->offerWithCodes(4);
        $inventory = app(VoucherInventory::class);
        $issued = [];
        foreach (range(1, 4) as $i) {
            $issued[] = $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'channel-'.$i), $consumer);
        }
        foreach (['daisycon', 'daisycon', 'tradetracker'] as $i => $network) {
            $this->insertAffiliate($network, 'approved', 10, 'CHANNEL-'.$i);
            $t = AffiliateTransaction::where('external_id', 'CHANNEL-'.$i)->sole();
            $t->forceFill(['user_id' => $consumer->id, 'webshop_id' => $this->shop->id])->save();
            $inventory->recordAcquisition($issued[$i], $t, $this->admin, 'SYNTHETIC-CASE-'.$i);
        }
        $this->insertAffiliate('tradetracker', 'approved', 999, 'NO-VOUCHER');
        Sanctum::actingAs($this->admin);
        $channels = $this->getJson('/api/v1/admin/cockpit?period=30d')->assertOk()->json('data.affiliate_channels');
        $this->assertSame('vouchers', $channels['metric']);
        $this->assertSame(4, $channels['total']);
        $byNet = collect($channels['channels'])->keyBy('network');
        $this->assertSame(2, $byNet['daisycon']['count']);
        $this->assertSame(50, $byNet['daisycon']['percent']);
        $this->assertSame(1, $byNet['tradetracker']['count']);
        $this->assertSame(1, $byNet['other']['count']);
        $this->assertStringContainsString('tab=vouchers', $byNet['daisycon']['href']);
        $this->getJson('/api/v1/admin/vouchers?acquisition_network=daisycon&from=2026-10-24&to=2026-10-24')
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/vouchers?acquisition_network=other')->assertOk()->assertJsonPath('meta.total', 1);
        $t->forceFill(['user_id' => $this->owner->id])->save();
        try {
            $inventory->recordAcquisition($issued[3], $t, $this->admin, 'SYNTHETIC-CASE');
            $this->fail('Mismatched consumer evidence accepted');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_console_acquisition_workflow_requires_review_and_preserves_immutable_audited_links(): void
    {
        $consumer = User::factory()->create();
        $inventory = app(VoucherInventory::class);
        $voucher = $inventory->issue($inventory->reserve($this->offerWithCodes(1), VoucherOrigin::MerchantReward, 'console-evidence'), $consumer);
        $this->insertAffiliate('daisycon', 'approved', 10, 'CONSOLE-ACQUISITION');
        $transaction = AffiliateTransaction::where('external_id', 'CONSOLE-ACQUISITION')->sole();
        $transaction->forceFill(['user_id' => $consumer->id, 'webshop_id' => $this->shop->id])->save();
        $args = ['voucher' => $voucher->public_id, 'transaction' => $transaction->public_id, '--actor' => $this->admin->public_id, '--reference' => 'SYNTHETIC-RECONCILIATION-42'];
        $this->artisan('shopbonus:voucher-acquisition', $args)->assertFailed();
        $args['--verified-link'] = true;
        $this->artisan('shopbonus:voucher-acquisition', array_replace($args, ['--reference' => '']))->assertFailed();
        $verifiedAt = $this->admin->email_verified_at;
        $this->admin->forceFill(['email_verified_at' => null])->save();
        $this->artisan('shopbonus:voucher-acquisition', $args)->assertFailed();
        $this->admin->forceFill(['email_verified_at' => $verifiedAt])->save();
        $this->admin->platformRoles()->update(['revoked_at' => now()]);
        $this->artisan('shopbonus:voucher-acquisition', $args)->assertFailed();
        $this->admin->platformRoles()->update(['revoked_at' => null]);
        $this->artisan('shopbonus:voucher-acquisition', array_replace($args, ['--actor' => $consumer->public_id]))->assertFailed();
        foreach (['status' => 'pending', 'user_id' => $this->owner->id, 'webshop_id' => $this->activeWebshop($this->owner)->id, 'duplicate_of_id' => $transaction->id, 'transaction_at' => now()->addMinute()] as $key => $invalid) {
            $original = $transaction->getRawOriginal($key);
            $transaction->forceFill([$key => $invalid])->save();
            $this->artisan('shopbonus:voucher-acquisition', $args)->assertFailed();
            $transaction->forceFill([$key => $original])->save();
        }
        $this->artisan('shopbonus:voucher-acquisition', array_replace($args, ['--reference' => "bad\nreference"]))->assertFailed();
        $this->assertNull($voucher->fresh()->acquisition_transaction_id);
        $this->artisan('shopbonus:voucher-acquisition', $args)->assertSuccessful();
        $this->artisan('shopbonus:voucher-acquisition', $args)->assertSuccessful();
        $this->assertSame($transaction->id, $voucher->fresh()->acquisition_transaction_id);
        $this->assertSame(1, VoucherEvent::where('voucher_id', $voucher->id)->where('type', 'acquisition_verified')->count());
        $event = VoucherEvent::where('voucher_id', $voucher->id)->where('type', 'acquisition_verified')->sole();
        $this->assertSame($this->admin->id, $event->actor_user_id);
        $this->assertSame('SYNTHETIC-RECONCILIATION-42', $event->summary['reference']);
        $this->assertSame(1, AuditEvent::where('action', 'voucher.acquisition_verified')->where('actor_user_id', $this->admin->id)->count());
        $this->insertAffiliate('tradetracker', 'approved', 10, 'DIFFERENT-SOURCE');
        $other = AffiliateTransaction::where('external_id', 'DIFFERENT-SOURCE')->sole();
        $other->forceFill(['user_id' => $consumer->id, 'webshop_id' => $this->shop->id])->save();
        $this->artisan('shopbonus:voucher-acquisition', array_replace($args, ['transaction' => $other->public_id]))->assertFailed();
        $this->assertSame($transaction->id, $voucher->fresh()->acquisition_transaction_id);
        $this->admin->forceFill(['status' => UserStatus::Suspended])->save();
        $this->artisan('shopbonus:voucher-acquisition', $args)->assertFailed();
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

    public function test_hash_search_is_exact_normalized_and_masked(): void
    {
        $consumer = User::factory()->create();
        $offer = $this->offerWithCodes(1);
        $code = VoucherCode::where('offer_id', $offer->id)->sole();
        $code->forceFill(['code' => 'Case-Code-1234', 'code_hash' => VoucherCode::hashFor($this->shop->id, 'Case-Code-1234'), 'code_last4' => '1234'])->save();
        $v = app(VoucherInventory::class)->issue(app(VoucherInventory::class)->reserve($offer, VoucherOrigin::MerchantReward, 'hash'), $consumer);
        Sanctum::actingAs($this->admin);
        $found = $this->getJson('/api/v1/admin/search?q='.urlencode(' case-code-1234 '))->assertOk()->json('data.groups');
        $this->assertSame($v->public_id, collect($found)->firstWhere('type', 'vouchers')['results'][0]['id']);
        $this->assertStringNotContainsString('Case-Code', json_encode($found));
        $partial = $this->getJson('/api/v1/admin/search?q=Code-1234')->assertOk()->json('data.groups');
        $this->assertNull(collect($partial)->firstWhere('type', 'vouchers'));
    }

    public function test_usable_kpi_and_destination_exclude_invalid_codes_and_expiry_boundary(): void
    {
        $consumer = User::factory()->create();
        $offer = $this->offerWithCodes(4);
        $inventory = app(VoucherInventory::class);
        foreach (range(1, 4) as $i) {
            $v = $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'usable-'.$i), $consumer);
            if ($i === 1) {
                $v->code->forceFill(['status' => 'invalid'])->save();
            }
            if ($i === 2) {
                $v->code->forceFill(['platform_validation' => 'mismatch'])->save();
            }
            if ($i === 3) {
                $v->forceFill(['expires_at' => now()])->save();
            }
        }
        Sanctum::actingAs($this->admin);
        $data = $this->getJson('/api/v1/admin/cockpit')->assertOk()->json('data');
        $this->assertSame(1, $data['kpis']['vouchers']['outstanding']);
        $this->getJson('/api/v1/admin/vouchers?usable=1')->assertOk()->assertJsonPath('meta.total', 1);
        foreach ($data['cards'] as $card) {
            if ($card['key'] !== 'affiliate_commission') {
                $this->assertNull($card['change_percent']);
            }
        }
    }

    public function test_consumer_memberships_and_explicit_test_exclusion_match_destination(): void
    {
        $consumer = User::factory()->create(['onboarding_intent' => 'consumer']);
        $this->activeWebshop($consumer);
        $fixture = User::factory()->create(['onboarding_intent' => 'consumer']);
        config(['shopbonus.admin.test_account_public_ids' => [$fixture->public_id]]);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/cockpit')->assertOk()->assertJsonPath('data.kpis.consumers.registered', 1);
        $this->getJson('/api/v1/admin/users?role=consumer&status=active')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $consumer->public_id);
    }

    public function test_affiliate_destination_uses_business_day_dates_for_rows_and_totals(): void
    {
        $this->insertAffiliate('daisycon', 'approved', 100, 'IN');
        $this->insertAffiliate('daisycon', 'approved', 500, 'OUT');
        DB::table('affiliate_transactions')->where('external_id', 'IN')->update(['transaction_at' => '2026-10-23 22:00:00']);
        DB::table('affiliate_transactions')->where('external_id', 'OUT')->update(['transaction_at' => '2026-10-24 22:00:00']);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/affiliate/transactions?network=daisycon&status=approved&from=2026-10-24&to=2026-10-24')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('totals.approved.commission_minor', 100);
        $this->getJson('/api/v1/admin/affiliate/transactions?from=2026-10-24')->assertUnprocessable();
        $this->getJson('/api/v1/admin/cockpit?period=custom&from=2026-10-24&to=2026-10-23')->assertUnprocessable();
    }

    public function test_failed_sources_are_deduplicated_by_connection_and_resolve_from_source_state(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/v1/workspaces/'.$this->shop->public_id.'/connections', ['platform' => 'prestashop'])->assertCreated();
        $connection = WebshopConnection::where('webshop_id', $this->shop->id)->sole();
        $connection->forceFill(['status' => 'error', 'last_error_code' => 'timeout'])->save();
        foreach (range(1, 2) as $i) {
            WebhookEvent::create(['connection_id' => $connection->id, 'webshop_id' => $this->shop->id,
                'dedupe_key' => 'failed-'.$i, 'status' => 'failed', 'payload_hash' => hash('sha256', 'payload-'.$i), 'received_at' => now()]);
        }
        $this->insertAffiliate('daisycon', 'approved', 10, 'SYNC');
        DB::table('affiliate_network_connections')->update(['status' => 'reauthentication_required']);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/cockpit')->assertOk()->assertJsonPath('data.attention.open_actions.failed_syncs.count', 2);
        $this->getJson('/api/v1/admin/integrations?connection='.$connection->public_id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/integrations/failed-events?connection='.$connection->public_id)->assertOk()->assertJsonPath('meta.total', 2);
        $connection->forceFill(['status' => 'connected'])->save();
        Cache::flush();
        $this->getJson('/api/v1/admin/cockpit')->assertOk()->assertJsonPath('data.attention.open_actions.failed_syncs.count', 2);
        DB::table('webhook_events')->update(['status' => 'processed']);
        DB::table('affiliate_network_connections')->update(['status' => 'connected', 'last_error' => null]);
        Cache::flush();
        $this->getJson('/api/v1/admin/cockpit')->assertOk()->assertJsonPath('data.attention.open_actions.failed_syncs.count', 0);
    }

    public function test_only_period_events_have_comparisons_and_custom_links_use_local_dates(): void
    {
        $this->insertAffiliate('daisycon', 'approved', 200, 'CURRENT');
        $this->insertAffiliate('daisycon', 'approved', 100, 'PREVIOUS');
        DB::table('affiliate_transactions')->where('external_id', 'PREVIOUS')->update(['transaction_at' => now()->subDays(7)]);
        Sanctum::actingAs($this->admin);
        $cards = collect($this->getJson('/api/v1/admin/cockpit?period=7d')->assertOk()->json('data.cards'))->keyBy('key');
        $this->assertEquals(100, $cards['affiliate_commission']['change_percent']);
        $this->assertNull($cards['active_webshops']['change_percent']);
        $custom = collect($this->getJson('/api/v1/admin/cockpit?period=custom&from=2026-10-24&to=2026-10-24')->assertOk()->json('data.cards'))->keyBy('key');
        $this->assertStringContainsString('tab=transactions&status=approved&from=2026-10-24&to=2026-10-24', $custom['affiliate_commission']['href']);
    }

    public function test_previous_period_uses_equal_local_days_across_dst(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'Europe/Amsterdam'));
        $this->insertAffiliate('daisycon', 'approved', 100, 'DST-CURRENT');
        $this->insertAffiliate('daisycon', 'approved', 100, 'DST-PREVIOUS');
        $this->insertAffiliate('daisycon', 'approved', 900, 'BEFORE-PREVIOUS');
        DB::table('affiliate_transactions')->where('external_id', 'DST-PREVIOUS')->update(['transaction_at' => '2026-09-01 22:00:00']);
        DB::table('affiliate_transactions')->where('external_id', 'BEFORE-PREVIOUS')->update(['transaction_at' => '2026-09-01 21:30:00']);
        Sanctum::actingAs($this->admin);
        $cards = collect($this->getJson('/api/v1/admin/cockpit?period=30d')->assertOk()->json('data.cards'))->keyBy('key');
        $this->assertEquals(0, $cards['affiliate_commission']['change_percent']);
    }

    public function test_voucher_and_activity_drilldowns_keep_webshop_scope_across_pages(): void
    {
        $other = $this->activeWebshop($this->owner, ['name' => 'Other shop']);
        $offer = $this->offerWithCodes(4);
        $otherOffer = $this->offerWithCodes(2, $other);
        $inventory = app(VoucherInventory::class);
        $consumer = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'scoped-'.$i), $consumer);
            app(AuditLogger::class)->record('fixture.drilldown', category: AuditCategory::Admin, webshopId: $this->shop->id);
        }
        $inventory->issue($inventory->reserve($otherOffer, VoucherOrigin::MerchantReward, 'other-scoped'), $consumer);
        app(AuditLogger::class)->record('fixture.drilldown', category: AuditCategory::Admin, webshopId: $other->id);
        Sanctum::actingAs($this->admin);
        foreach (['vouchers', 'activity'] as $list) {
            $extra = $list === 'activity' ? '&action=fixture.drilldown' : '';
            foreach (['webshop', 'webshop_id'] as $filter) {
                $first = $this->getJson('/api/v1/admin/'.$list.'?'.$filter.'='.$this->shop->public_id.'&per_page=2'.$extra)->assertOk();
                $second = $this->getJson('/api/v1/admin/'.$list.'?'.$filter.'='.strtoupper($this->shop->public_id).'&per_page=2&page=2'.$extra)->assertOk();
                $first->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)->assertJsonCount(2, 'data');
                $second->assertJsonPath('meta.total', 3)->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data');
                $this->assertSame([$this->shop->public_id], array_values(array_unique(array_column(array_column($second->json('data'), 'webshop'), 'id'))));
                $this->assertNotContains($second->json('data.0.id'), array_column($first->json('data'), 'id'));
            }
            $this->getJson('/api/v1/admin/'.$list.'?webshop='.strtolower((string) Str::ulid()).$extra)->assertOk()->assertJsonPath('meta.total', 0);
            $this->getJson('/api/v1/admin/'.$list.'?webshop=invalid')->assertUnprocessable();
            $this->getJson('/api/v1/admin/'.$list.'?webshop='.$this->shop->public_id.'&webshop_id='.$other->public_id)->assertUnprocessable();
            $this->getJson('/api/v1/admin/'.$list.'?webshop='.$this->shop->public_id.'&webshop_id='.strtoupper($this->shop->public_id).$extra)->assertOk()->assertJsonPath('meta.total', 3);
        }
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/admin/vouchers?webshop='.$other->public_id)->assertForbidden();
        $this->getJson('/api/v1/admin/activity?webshop='.$other->public_id)->assertForbidden();
    }

    public function test_admin_lists_expose_page_two_and_validate_page_inputs(): void
    {
        User::factory()->count(3)->create(['first_name' => 'Pagination fixture']);
        $this->activeWebshop($this->owner, ['name' => 'Pagination one']);
        $this->activeWebshop($this->owner, ['name' => 'Pagination two']);
        Sanctum::actingAs($this->admin);
        foreach (['users' => 'search=Pagination', 'webshops' => 'search=Pagination'] as $list => $filter) {
            $first = $this->getJson('/api/v1/admin/'.$list.'?per_page=1&'.$filter)->assertOk();
            $second = $this->getJson('/api/v1/admin/'.$list.'?per_page=1&page=2&'.$filter)->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data');
            $this->assertNotSame($first->json('data.0.id'), $second->json('data.0.id'));
            if ($list === 'webshops') {
                $this->assertStringContainsString('search=Pagination', $second->json('links.next') ?? $second->json('links.prev'));
            }
        }
        foreach (['users', 'webshops', 'vouchers', 'activity'] as $list) {
            foreach (['page=0', 'page=-1', 'page[]=1', 'page=abc', 'per_page=101'] as $bad) {
                $this->getJson('/api/v1/admin/'.$list.'?'.$bad)->assertUnprocessable();
            }
        }
    }

    public function test_today_exposes_full_business_metrics_from_records_without_client_cards(): void
    {
        $consumer = User::factory()->create();
        $wallets = app(WalletService::class);
        $points = $wallets->consumerWallet($consumer, WalletUnit::Points);
        $wallets->credit($points, 100, 'fixture-points-issued');
        $wallets->debit($points, 20, 'fixture-points-spent');
        $this->offerWithCodes(2);
        $connection = WebshopConnection::create(['webshop_id' => $this->shop->id, 'platform' => 'prestashop',
            'method' => 'private_module', 'status' => 'limited', 'webhook_token' => 'synthetic', 'created_by' => $this->owner->id]);
        WebshopOrder::create(['webshop_id' => $this->shop->id, 'connection_id' => $connection->id, 'platform' => 'prestashop',
            'external_order_id' => 'fixture-paid', 'reference' => 'fixture-paid', 'amount_minor' => 1000, 'currency' => 'EUR',
            'payment_status' => 'paid', 'ingestion_status' => 'complete', 'paid_at' => now()]);
        ContactOpportunity::create(['webshop_id' => $this->shop->id, 'customer_key' => str_repeat('a', 64),
            'status' => 'pending', 'lawful_basis' => 'consent_at_checkout', 'window_ends_at' => now()->addHour(), 'idempotency_key' => 'fixture-contact']);
        VoucherDispute::create(['user_id' => $consumer->id, 'webshop_id' => $this->shop->id,
            'reason' => 'other', 'description' => 'Synthetic dashboard count', 'status' => 'open']);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/admin/cockpit?period=today')->assertOk()->assertJsonPath('data.period_name', 'today')
            ->assertJsonPath('data.kpis.orders.confirmed_paid_in_period', 1)->assertJsonPath('data.kpis.answer24.requests_in_period', 1)
            ->assertJsonPath('data.kpis.answer24.by_status.pending', 1)->assertJsonPath('data.kpis.vouchers.available_codes', 2)
            ->assertJsonPath('data.kpis.wallets.points.issued_in_period', 100)->assertJsonPath('data.kpis.wallets.points.spent_in_period', 20)
            ->assertJsonPath('data.kpis.wallets.points.outstanding', 80)->assertJsonPath('data.kpis.disputes.open', 1);
    }
}
