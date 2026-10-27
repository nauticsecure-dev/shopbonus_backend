<?php

namespace Tests\Feature\Affiliate;

use App\Enums\PlatformRole;
use App\Models\AffiliateClick;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReconciliationFlag;
use App\Models\Webshop;
use App\Services\Affiliate\AffiliateSync;
use App\Services\Affiliate\ProgramSelector;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B10: affiliate tracking and rewards (Trello #19 E2E 2–7, 9; #23 test 6).
 * Network payloads are fixtures in the networks' documented shapes: the
 * adapters are NOT VERIFIED against live accounts.
 */
class AffiliateTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private User $anna;

    private Webshop $shop;

    private AffiliateConnection $daisycon;

    private AffiliateConnection $tradetracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->anna = User::factory()->create(['email' => 'anna@example.nl']);

        $owner = User::factory()->create();
        $this->shop = $this->activeWebshop($owner, ['name' => 'Fietsen Utrecht', 'verified_domain' => 'fietsen-utrecht.nl']);
        Sanctum::actingAs($owner);
        $this->putJson("/api/v1/workspaces/{$this->shop->public_id}/public-profile", ['short_description' => 'Fietsen', 'website_url' => 'https://fietsen-utrecht.nl', 'categories' => ['sports_outdoor'], 'published' => true])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->daisycon = $this->connection('daisycon', ['publisher_id' => '123', 'access_token' => 'tok', 'media_id' => '9']);
        $this->tradetracker = $this->connection('tradetracker', ['customer_id' => '456', 'passphrase' => 'secret', 'site_id' => '7']);
        AffiliateRewardRule::create(['version' => 1, 'unit' => 'eur_credit', 'share_bps' => 5000, 'min_commission_minor' => 50, 'review_above_minor' => 5000, 'active' => true, 'created_by' => $this->admin->id]);
    }

    private function connection(string $network, array $credentials): AffiliateConnection
    {
        return AffiliateConnection::create(['network' => $network, 'name' => ucfirst($network), 'status' => 'connected', 'credentials' => $credentials,
            'postback_token_hash' => AffiliateConnection::hashToken($network.'-token')]);
    }

    private function daisyconProgram(array $commission = ['type' => 'percentage', 'value' => '6', 'restricted' => false]): array
    {
        return ['id' => 'P1', 'name' => 'Fietsen Utrecht', 'url' => 'https://www.fietsen-utrecht.nl', 'status' => 'active', 'currency_code' => 'EUR',
            'commission' => $commission, 'cashback_allowed' => true, 'tracking_url' => 'https://lt45.net/c/?si=1&li=2&wi=3'];
    }

    private function tradetrackerProgram(bool $restricted = false): array
    {
        return ['ID' => 'T1', 'name' => 'Fietsen Utrecht', 'URL' => 'https://fietsen-utrecht.nl', 'info' => ['assignmentStatus' => 'accepted', 'policyCashbackStatus' => true,
            'commission' => ['saleCommissionVariable' => '8', 'categoryRestricted' => $restricted], 'trackingURL' => 'https://tc.tradetracker.net/?c=1&m=2&a=3']];
    }

    private function daisyconTx(string $id, ?string $subid, string $status, string $updated = '2026-10-23T12:00:00Z', float $commission = 4.797, string $order = 'ORD-1'): array
    {
        return ['affiliatemarketing_id' => $id, 'program_id' => 'P1', 'date' => now()->toIso8601String(), 'last_modified' => $updated,
            'parts' => [['subid' => $subid, 'referencenumber' => $order, 'revenue' => 79.95, 'commission' => $commission, 'status' => $status, 'currency_code' => 'EUR']]];
    }

    private function import(AffiliateConnection $c, array $programs = [], array $transactions = []): array
    {
        return app(AffiliateSync::class)->import($c, $programs, $transactions, $this->admin->id);
    }

    /** Signed-in Anna clicks "Visit webshop"; returns the click reference. */
    private function annaClicks(): string
    {
        Sanctum::actingAs($this->anna);
        $this->get('/api/v1/public/webshops/fietsen-utrecht/visit')->assertRedirect();
        $this->app['auth']->forgetGuards();

        return AffiliateClick::latest('id')->firstOrFail()->reference;
    }

    private function wallet(): Wallet
    {
        return app(WalletService::class)->consumerWallet($this->anna);
    }

    private function asAdminWithStepUp(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_card19_e2e2_highest_comparable_rate_wins_and_restricted_rates_are_not_compared(): void
    {
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $this->import($this->tradetracker, [$this->tradetrackerProgram()]);
        // Auto-matched on the merchant's verified domain.
        $this->assertSame(2, AffiliateProgram::where('webshop_id', $this->shop->id)->count());

        $choice = app(ProgramSelector::class)->select($this->shop);
        $this->assertSame(['tradetracker', 'best_comparable', null], [$choice['program']->connection->network, $choice['method'], $choice['limitation']]);
        $decision = DB::table('affiliate_program_selections')->latest('id')->first();
        $this->assertSame([300, 400], array_column(json_decode($decision->candidates, true), 'expected_commission_minor'));

        $this->import($this->tradetracker, [$this->tradetrackerProgram(restricted: true)]);
        $choice = app(ProgramSelector::class)->select($this->shop);
        $this->assertSame(['daisycon', 'fallback', 'restricted_rates_not_comparable'], [$choice['program']->connection->network, $choice['method'], $choice['limitation']]);
    }

    public function test_card19_e2e3_visit_redirects_through_the_network_and_awards_nothing(): void
    {
        $this->import($this->tradetracker, [$this->tradetrackerProgram()]);

        Sanctum::actingAs($this->anna);
        $response = $this->get('/api/v1/public/webshops/fietsen-utrecht/visit');
        $click = AffiliateClick::sole();
        $response->assertRedirect('https://tc.tradetracker.net/?c=1&m=2&a=3&r='.$click->reference);
        $this->assertSame($this->anna->id, $click->user_id);
        $this->assertSame(20, strlen($click->reference));
        $this->assertSame(0, $this->wallet()->fresh()->available + $this->wallet()->fresh()->pending);

        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/public/webshops/fietsen-utrecht/visit')->assertRedirect();
        $this->assertNull(AffiliateClick::latest('id')->first()->user_id); // anonymous: tracked, not attributed
        $this->getJson('/api/v1/public/webshops/bestaat-niet/visit')->assertNotFound();

        AffiliateProgram::query()->update(['active' => false]);
        $this->get('/api/v1/public/webshops/fietsen-utrecht/visit')->assertRedirect('https://fietsen-utrecht.nl');
        $this->assertSame(2, AffiliateClick::count());
    }

    public function test_card19_e2e4_and_e2e6_import_normalises_pending_is_not_spendable_and_approval_credits_once(): void
    {
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $ref = $this->annaClicks();

        $result = $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'pending')]);
        $this->assertSame([1, []], [$result['transactions'], $result['rejected']]);
        $tx = AffiliateTransaction::sole();
        $this->assertSame([7995, 479, 'pending', 'pending', 239], [$tx->order_amount_minor, $tx->commission_minor, $tx->status, $tx->reward_status, $tx->reward_amount]);
        $this->assertSame([0, 239], [$this->wallet()->fresh()->available, $this->wallet()->fresh()->pending]);

        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'approved', '2026-10-24T09:00:00Z')]);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'approved', '2026-10-24T09:00:00Z')]); // retry
        $this->assertSame([239, 0, 239], [$this->wallet()->fresh()->available, $this->wallet()->fresh()->pending, $this->wallet()->fresh()->total_earned]);
        $this->assertSame('credited', $tx->fresh()->reward_status);
        $this->assertSame([], app(WalletService::class)->reconcile());

        // A rejected row in the import is reported, the rest goes through.
        $bad = $this->import($this->daisycon, [], [['affiliatemarketing_id' => 'X']]);
        $this->assertCount(1, $bad['rejected']);
    }

    public function test_card19_e2e4_unknown_or_inconsistent_conversions_enter_review(): void
    {
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $other = $this->activeWebshop(User::factory()->create(), ['name' => 'Ander']);
        $foreign = AffiliateClick::create(['reference' => 'FOREIGNCLICK00000001', 'user_id' => $this->anna->id, 'webshop_id' => $other->id, 'program_id' => AffiliateProgram::sole()->id]);

        $this->import($this->daisycon, [], [
            $this->daisyconTx('D-1', null, 'approved', order: 'A'),
            $this->daisyconTx('D-2', 'NOSUCHCLICK', 'approved', order: 'B'),
            $this->daisyconTx('D-3', $foreign->reference, 'approved', order: 'C'),
            ['affiliatemarketing_id' => 'D-4', 'program_id' => 'UNKNOWN', 'date' => now()->toIso8601String(), 'parts' => [['revenue' => 10, 'commission' => 1, 'status' => 'approved']]],
        ]);

        $this->assertSame(['no_click_reference', 'unknown_click', 'click_for_other_webshop', 'unknown_program'], AffiliateTransaction::orderBy('id')->pluck('review_reason')->all());
        $this->assertSame(['unmatched'], AffiliateTransaction::distinct()->pluck('status')->all());
        $this->assertSame(0, $this->wallet()->fresh()->available);

        // Review: link the first conversion to a real click of Anna's.
        $ref = $this->annaClicks();
        $this->asAdminWithStepUp();
        $id = AffiliateTransaction::where('external_id', 'D-1')->value('public_id');
        $this->postJson("/api/v1/admin/affiliate/transactions/{$id}/resolve", ['action' => 'link_click', 'click_reference' => $ref, 'note' => 'Order confirmed by merchant'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.reward.status', 'credited');
        $this->assertSame(239, $this->wallet()->fresh()->available);
    }

    public function test_card19_e2e5_replays_and_cross_network_reports_never_reward_twice(): void
    {
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $this->import($this->tradetracker, [$this->tradetrackerProgram()]);
        $ref = $this->annaClicks();

        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'approved')]);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'approved')]);
        // The same purchase reported by TradeTracker (same order reference).
        $this->import($this->tradetracker, [], [['ID' => 'TT-9', 'campaign' => ['ID' => 'T1'], 'reference' => 'OTHER', 'orderID' => 'ORD-1', 'orderAmount' => '79.95',
            'commission' => '6.40', 'transactionStatus' => 'accepted', 'registrationDate' => now()->toIso8601String()]]);

        $this->assertSame(2, AffiliateTransaction::count());
        $tt = AffiliateTransaction::where('network', 'tradetracker')->sole();
        $this->assertSame(['disputed', 'duplicate', 'none'], [$tt->status, $tt->review_reason, $tt->reward_status]);
        $this->assertSame(AffiliateTransaction::where('network', 'daisycon')->value('id'), $tt->duplicate_of_id);
        $this->assertSame(239, $this->wallet()->fresh()->total_earned);
    }

    public function test_card19_e2e7_rejection_cancels_pending_and_reversal_never_reclaims_spent_credit(): void
    {
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $ref1 = $this->annaClicks();
        $ref2 = $this->annaClicks();

        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref1, 'pending', order: 'O1')]);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref1, 'disapproved', '2026-10-24T09:00:00Z', order: 'O1')]);
        $this->assertSame(['rejected', 'cancelled'], [AffiliateTransaction::where('external_id', 'D-1')->value('status'), AffiliateTransaction::where('external_id', 'D-1')->value('reward_status')]);
        $this->assertSame([0, 0], [$this->wallet()->fresh()->available, $this->wallet()->fresh()->pending]);

        $this->import($this->daisycon, [], [$this->daisyconTx('D-2', $ref2, 'approved', order: 'O2')]);
        app(WalletService::class)->debit($this->wallet(), 200, 'voucher-purchase'); // Anna spends most of it
        $this->import($this->daisycon, [], [$this->daisyconTx('D-2', $ref2, 'reversed', '2026-10-25T09:00:00Z', order: 'O2')]);

        $tx = AffiliateTransaction::where('external_id', 'D-2')->sole();
        $this->assertSame(['reversed', 'reversal_flagged'], [$tx->status, $tx->reward_status]);
        $this->assertSame(0, $this->wallet()->fresh()->available); // 39 unspent reversed; never negative
        $this->assertSame(200, (int) WalletReconciliationFlag::where('reason', 'reversal_exceeds_unspent')->value('amount'));
    }

    public function test_card19_e2e9_postbacks_cannot_approve_and_stale_updates_are_ignored(): void
    {
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $ref = $this->annaClicks();

        $this->postJson('/api/v1/affiliate/postback/daisycon-token', $this->daisyconTx('D-1', $ref, 'approved'))->assertStatus(202)->assertJsonPath('data.status', 'tracked');
        $this->assertSame([0, 0], [$this->wallet()->fresh()->available, $this->wallet()->fresh()->pending]);
        $this->postJson('/api/v1/affiliate/postback/wrong-token', $this->daisyconTx('D-9', $ref, 'approved'))->assertNotFound();

        // The trusted import then processes it; a later postback cannot change it.
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'pending', '2026-10-24T10:00:00Z')]);
        $this->postJson('/api/v1/affiliate/postback/daisycon-token', $this->daisyconTx('D-1', $ref, 'approved', '2026-10-25T10:00:00Z'))->assertStatus(202);
        $this->assertSame(['pending', 239], [AffiliateTransaction::sole()->status, $this->wallet()->fresh()->pending]);

        // Out of order: an older approval after a newer rejection is ignored.
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'disapproved', '2026-10-26T10:00:00Z')]);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'approved', '2026-10-25T10:00:00Z')]);
        $this->assertSame(['rejected', 0], [AffiliateTransaction::sole()->status, $this->wallet()->fresh()->available]);
    }

    public function test_card23_test6_instant_credit_mode_credits_once_and_rejection_does_not_reverse(): void
    {
        config(['shopbonus.affiliate.credit_timing' => 'on_validated_attribution']);
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $ref = $this->annaClicks();

        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'pending', commission: 9.50)]);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'pending', commission: 9.50)]); // replay
        $this->assertSame([475, 0], [$this->wallet()->fresh()->available, $this->wallet()->fresh()->pending]); // €4.75 immediately

        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'disapproved', '2026-10-24T09:00:00Z', commission: 9.50)]);
        $tx = AffiliateTransaction::sole();
        $this->assertSame(['rejected', 'credited', 'network_rejected_after_credit'], [$tx->status, $tx->reward_status, $tx->review_reason]);
        $this->assertSame(475, $this->wallet()->fresh()->available);
        $lotExpiry = DB::table('wallet_credit_lots')->where('wallet_id', $this->wallet()->id)->value('expires_at');
        $this->assertEqualsWithDelta(now()->addMonths(12)->getTimestamp(), strtotime((string) $lotExpiry), 86400); // 12-month validity
    }

    public function test_rewards_above_the_review_threshold_wait_for_an_admin(): void
    {
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $ref = $this->annaClicks();

        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $ref, 'approved', commission: 250.00)]);
        $tx = AffiliateTransaction::sole();
        $this->assertSame(['disputed', 'review_threshold'], [$tx->status, $tx->review_reason]);
        $this->assertSame(0, $this->wallet()->fresh()->available);

        $this->asAdminWithStepUp();
        $this->postJson("/api/v1/admin/affiliate/transactions/{$tx->public_id}/resolve", ['action' => 'accept', 'note' => 'Large order verified with merchant'])
            ->assertOk()->assertJsonPath('data.reward.status', 'credited');
        $this->assertSame(12500, $this->wallet()->fresh()->available);
        $this->postJson("/api/v1/admin/affiliate/transactions/{$tx->public_id}/resolve", ['action' => 'accept', 'note' => 'again'])->assertStatus(409);
    }

    public function test_no_active_rule_means_no_reward(): void
    {
        AffiliateRewardRule::query()->update(['active' => false]);
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $this->annaClicks(), 'approved')]);

        $this->assertSame(['approved', 'none'], [AffiliateTransaction::sole()->status, AffiliateTransaction::sole()->reward_status]);
        $this->assertSame(0, $this->wallet()->fresh()->available);
    }

    public function test_admin_connection_setup_rules_and_consumer_activity(): void
    {
        Http::fake(['services.daisycon.com/*' => Http::response(['error' => 'invalid_token'], 401)]);
        $this->asAdminWithStepUp();

        $this->postJson('/api/v1/admin/affiliate/connections', ['network' => 'daisycon', 'name' => 'Daisycon NL', 'credentials' => ['publisher_id' => 'abc']])->assertUnprocessable();
        $created = $this->postJson('/api/v1/admin/affiliate/connections', ['network' => 'daisycon', 'name' => 'Daisycon NL', 'credentials' => ['publisher_id' => '1', 'access_token' => 'bad', 'media_id' => '2']])
            ->assertCreated()->assertJsonPath('data.status', 'reauthentication_required')->assertJsonPath('data.verified', false);
        $this->assertStringContainsString('/api/v1/affiliate/postback/', $created->json('data.postback_url'));
        $this->assertArrayNotHasKey('credentials', $created->json('data'));
        $this->getJson('/api/v1/admin/affiliate/connections')->assertOk()->assertJsonMissingPath('data.0.postback_url');

        $this->postJson('/api/v1/admin/affiliate/reward-rules', ['unit' => 'eur_credit', 'share_bps' => 10000])->assertCreated()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/admin/affiliate/reward-rules')->assertJsonPath('current', 2);

        // TradeTracker transport stays disabled until configured by the deployment owner.
        $this->postJson("/api/v1/admin/affiliate/connections/{$this->tradetracker->public_id}/sync")->assertOk()->assertJsonPath('data.connection.status', 'limited');

        // Consumer view: own activity, no commission data.
        $this->app['auth']->forgetGuards();
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $this->annaClicks(), 'pending')]);
        Sanctum::actingAs($this->anna);
        $activity = $this->getJson('/api/v1/consumer/affiliate-activity')->assertOk()->json('data.0');
        $this->assertSame(['Fietsen Utrecht', 'pending', 'pending'], [$activity['webshop'], $activity['status'], $activity['reward']['status']]);
        $this->assertStringNotContainsString('commission', json_encode($activity));
        Sanctum::actingAs(User::factory()->create());
        $this->assertSame([], $this->getJson('/api/v1/consumer/affiliate-activity')->json('data'));
    }

    public function test_approved_credit_uses_frozen_rule_and_timing_while_new_conversions_use_new_rates(): void
    {
        config(['shopbonus.affiliate.credit_timing' => 'on_network_approval']);
        AffiliateRewardRule::create(['version' => 2, 'unit' => 'eur_credit', 'share_bps' => 7000, 'min_commission_minor' => 0, 'active' => true, 'created_by' => $this->admin->id]);
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $click = $this->annaClicks();
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $click, 'pending', commission: 10, order: 'HIST-1')]);
        $tx = AffiliateTransaction::sole();
        $this->assertSame([0, 700], [$this->wallet()->fresh()->available, $this->wallet()->fresh()->pending]);
        AffiliateRewardRule::create(['version' => 3, 'unit' => 'eur_credit', 'share_bps' => 9000, 'min_commission_minor' => 0, 'active' => true, 'created_by' => $this->admin->id]);
        config(['shopbonus.affiliate.credit_timing' => 'on_validated_attribution']); // Existing pending obligation must not change regime.
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $click, 'pending', '2026-10-27T10:00:00Z', 10, 'HIST-1')]);
        $this->assertSame(0, $this->wallet()->fresh()->available);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-1', $click, 'approved', '2026-10-27T11:00:00Z', 10, 'HIST-1')]);
        $tx = $tx->fresh();
        $this->assertSame(700, $this->wallet()->fresh()->available);
        $this->assertSame('on_network_approval', $tx->credit_evidence['timing']);
        $this->assertSame(7000, $tx->credit_evidence['rule_terms']['share_bps']);
        $this->assertSame(1000, $tx->credit_evidence['commission_minor']);
        $this->assertSame(700, $tx->credit_evidence['reward_amount']);
        $original = $tx->credit_evidence;
        config(['shopbonus.affiliate.credit_timing' => 'on_network_approval']);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-2', $this->annaClicks(), 'approved', commission: 10, order: 'HIST-2')]);
        $this->assertSame(900, AffiliateTransaction::where('external_id', 'D-2')->sole()->reward_amount);
        $this->assertSame($original, $tx->fresh()->credit_evidence);
        $this->expectException(\LogicException::class);
        $tx->forceFill(['credit_evidence' => ['reward_amount' => 900]])->save();
    }

    public function test_reward_rule_history_cannot_be_overwritten(): void
    {
        $this->expectException(\LogicException::class);
        AffiliateRewardRule::sole()->forceFill(['share_bps' => 9000])->save();
    }

    public function test_increased_approved_commission_requires_review_again_before_becoming_spendable(): void
    {
        config(['shopbonus.affiliate.credit_timing' => 'on_network_approval']);
        AffiliateRewardRule::create(['version' => 2, 'unit' => 'eur_credit', 'share_bps' => 7000, 'min_commission_minor' => 0, 'review_above_minor' => 800, 'active' => true, 'created_by' => $this->admin->id]);
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $click = $this->annaClicks();
        $this->import($this->daisycon, [], [$this->daisyconTx('D-CHANGED', $click, 'pending', commission: 10)]);
        $this->import($this->daisycon, [], [$this->daisyconTx('D-CHANGED', $click, 'approved', '2026-10-27T11:00:00Z', 20)]);
        $tx = AffiliateTransaction::sole();
        $this->assertSame('disputed', $tx->status);
        $this->assertSame([0, 700], [$this->wallet()->fresh()->available, $this->wallet()->fresh()->pending]);
        $this->assertNull($tx->credit_evidence);
        $this->asAdminWithStepUp();
        $this->postJson('/api/v1/admin/affiliate/transactions/'.$tx->public_id.'/resolve', ['action' => 'accept', 'note' => 'Verified increased commission source'])->assertOk();
        $this->assertSame([1400, 0], [$this->wallet()->fresh()->available, $this->wallet()->fresh()->pending]);
        $this->assertSame('admin', $tx->fresh()->credit_evidence['source']);
    }

    public function test_foreign_commission_currency_cannot_silently_become_eur_wallet_credit(): void
    {
        config(['shopbonus.affiliate.credit_timing' => 'on_network_approval']);
        $this->import($this->daisycon, [$this->daisyconProgram()]);
        $data = $this->daisyconTx('D-USD', $this->annaClicks(), 'approved', commission: 10);
        $data['parts'][0]['currency_code'] = 'USD';
        $this->import($this->daisycon, [], [$data]);
        $tx = AffiliateTransaction::sole();
        $this->assertSame('disputed', $tx->status);
        $this->assertSame('unsupported_reward_currency', $tx->review_reason);
        $this->assertSame(0, $this->wallet()->fresh()->available);
        $this->assertDatabaseMissing('wallet_ledger_entries', ['source_type' => 'affiliate', 'source_ref' => $tx->public_id]);
        $this->asAdminWithStepUp();
        $this->postJson('/api/v1/admin/affiliate/transactions/'.$tx->public_id.'/resolve', ['action' => 'accept', 'note' => 'No approved FX mapping is configured'])->assertConflict()->assertJsonPath('error.code', 'unsupported_reward_currency');
        $this->assertSame('disputed', $tx->fresh()->status);
        $this->assertSame(0, $this->wallet()->fresh()->available);
    }
}
