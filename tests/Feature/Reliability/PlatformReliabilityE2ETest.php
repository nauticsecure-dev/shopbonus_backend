<?php

namespace Tests\Feature\Reliability;

use App\Enums\PlatformRole;
use App\Models\AffiliateClick;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliateRewardRule;
use App\Models\AffiliateTransaction;
use App\Models\AuditEvent;
use App\Models\FinancialReconciliationCheck;
use App\Models\MerchantCommissionAgreement;
use App\Models\MerchantCommissionConversion;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Affiliate\AffiliateSync;
use App\Services\Cashback\DirectMerchantCashback;
use App\Services\Reliability\FinancialReconciliation;
use App\Services\Reliability\FinancialSources;
use App\Services\Reliability\Operations;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * Ten card acceptance mappings for platform reliability (#30).
 * Reuses existing wallet/affiliate/monitoring/recovery suites; this file closes the
 * direct-merchant reconciliation gap and asserts the ops overview contract.
 */
class PlatformReliabilityE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private User $consumer;

    private Webshop $shop;

    private AffiliateConnection $daisycon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->consumer = User::factory()->create(['email_verified_at' => now()]);
        $this->shop = $this->activeWebshop(User::factory()->create(), ['name' => 'Reliability Shop']);
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

    /** 1. €10 / 70-30 affiliate credit + matched reconciliation. */
    public function test_01_affiliate_ten_euro_split_matches_reconciliation(): void
    {
        $this->seedProgram();
        $ref = $this->click();
        app(AffiliateSync::class)->import($this->daisycon, [], [[
            'affiliatemarketing_id' => 'REL-1', 'program_id' => 'P1', 'date' => now()->toIso8601String(),
            'last_modified' => now()->toIso8601String(),
            'parts' => [['subid' => $ref, 'referencenumber' => 'ORD-REL-1', 'revenue' => 100.00, 'commission' => 10.00, 'status' => 'approved', 'currency_code' => 'EUR']],
        ]], $this->admin->id);

        $tx = AffiliateTransaction::where('external_id', 'REL-1')->sole();
        $this->assertSame([1000, 700, 'credited'], [$tx->commission_minor, $tx->reward_amount, $tx->reward_status]);
        $this->assertSame(300, $tx->credit_evidence['allocation']['platform_unrecognized_minor']);
        $this->assertSame(700, app(WalletService::class)->consumerWallet($this->consumer)->fresh()->available);
        $this->assertSame('matched', app(FinancialSources::class)->inspect($tx)['status']);
    }

    /** 2. Duplicate affiliate delivery → one credit. */
    public function test_02_duplicate_affiliate_delivery_credits_once(): void
    {
        $this->seedProgram();
        $ref = $this->click();
        $payload = [[
            'affiliatemarketing_id' => 'REL-DUP', 'program_id' => 'P1', 'date' => now()->toIso8601String(),
            'last_modified' => now()->toIso8601String(),
            'parts' => [['subid' => $ref, 'referencenumber' => 'ORD-DUP', 'revenue' => 100.00, 'commission' => 10.00, 'status' => 'approved', 'currency_code' => 'EUR']],
        ]];
        $sync = app(AffiliateSync::class);
        $sync->import($this->daisycon, [], $payload, $this->admin->id);
        $sync->import($this->daisycon, [], $payload, $this->admin->id);
        $this->assertSame(1, AffiliateTransaction::where('external_id', 'REL-DUP')->count());
        $this->assertSame(1, WalletLedgerEntry::where('source_type', 'affiliate')->where('type', 'credit')->count());
    }

    /** 3. Direct merchant 70/30 then new 90/10 — history retains frozen snapshot. */
    public function test_03_commission_change_freezes_historical_direct_merchant_rates(): void
    {
        MerchantCommissionAgreement::create([
            'webshop_id' => $this->shop->id, 'version' => 1, 'commission_type' => 'fixed',
            'commission_fixed_minor' => 1000, 'consumer_share_bps' => 7000, 'shopbonus_share_bps' => 3000,
            'cashback_approved' => true, 'active' => true, 'created_by' => $this->admin->id,
        ]);
        $first = app(DirectMerchantCashback::class)->creditVerifiedConversion($this->shop, $this->consumer, (string) Str::uuid(), [
            'external_id' => 'DM-OLD', 'order_amount_minor' => 5000, 'commission_minor' => 1000,
        ]);
        $this->assertSame([700, 300, 'credited'], [$first->consumer_credit_minor, $first->shopbonus_revenue_minor, $first->status]);
        $this->assertSame('matched', app(FinancialSources::class)->inspect($first)['status']);

        MerchantCommissionAgreement::whereKey(
            MerchantCommissionAgreement::where('webshop_id', $this->shop->id)->where('version', 1)->value('id')
        )->update(['active' => false]);
        MerchantCommissionAgreement::create([
            'webshop_id' => $this->shop->id, 'version' => 2, 'commission_type' => 'fixed',
            'commission_fixed_minor' => 1000, 'consumer_share_bps' => 9000, 'shopbonus_share_bps' => 1000,
            'cashback_approved' => true, 'active' => true, 'created_by' => $this->admin->id,
        ]);
        $second = app(DirectMerchantCashback::class)->creditVerifiedConversion($this->shop, $this->consumer, (string) Str::uuid(), [
            'external_id' => 'DM-NEW', 'order_amount_minor' => 5000, 'commission_minor' => 1000,
        ]);
        $this->assertSame([900, 100], [$second->consumer_credit_minor, $second->shopbonus_revenue_minor]);
        $this->assertSame(700, $first->fresh()->consumer_credit_minor);
        $this->assertSame(7000, $first->fresh()->agreement_snapshot['consumer_share_bps']);
        $this->assertSame('matched', app(FinancialSources::class)->inspect($first->fresh())['status']);
        $this->assertSame('matched', app(FinancialSources::class)->inspect($second)['status']);
    }

    /** 4. Injected ledger mismatch → finding, no silent correction. */
    public function test_04_ledger_mismatch_creates_finding_without_silent_correction(): void
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->consumerWallet($this->consumer);
        $wallets->credit($wallet, 1000, 'mismatch-seed');
        DB::table('wallets')->where('id', $wallet->id)->update(['available' => 1400]);
        $runner = app(FinancialReconciliation::class);
        $run = $runner->advance($runner->start('mismatch:direct'));
        $this->assertGreaterThanOrEqual(1, $run->discrepancies);
        $this->assertSame('CRITICAL', $runner->summary($run)['status']);
        $this->assertSame(1400, $wallet->fresh()->available);
        $this->assertSame(1, WalletLedgerEntry::where('wallet_id', $wallet->id)->count());
        $evidence = FinancialReconciliationCheck::where('source_type', 'wallet')
            ->where('source_ref', $wallet->public_id)->sole()->evidence;
        $this->assertSame([1000, 1400], $evidence['mismatches']['available']);
    }

    /** 5. Ops overview never marks missing monitoring HEALTHY; includes security + acceptance metrics. */
    public function test_05_operations_overview_unknown_when_unobserved(): void
    {
        $report = app(Operations::class)->report();
        $this->assertSame('UNKNOWN', $report['components']['backups']['status']);
        $this->assertSame('UNKNOWN', $report['components']['security']['status']);
        $this->assertArrayHasKey('acceptance_metrics', $report['components']);
        $this->assertNotSame('HEALTHY', $report['components']['acceptance_metrics']['status']);
        $this->assertArrayHasKey('audit', $report['links']);
        $this->assertArrayHasKey('cockpit', $report['links']);
    }

    /** 6. Direct merchant credit is audited with redacted internals. */
    public function test_06_direct_merchant_credit_is_audited(): void
    {
        MerchantCommissionAgreement::create([
            'webshop_id' => $this->shop->id, 'version' => 1, 'commission_type' => 'fixed',
            'commission_fixed_minor' => 1000, 'consumer_share_bps' => 7000, 'shopbonus_share_bps' => 3000,
            'cashback_approved' => true, 'active' => true, 'created_by' => $this->admin->id,
        ]);
        $row = app(DirectMerchantCashback::class)->creditVerifiedConversion($this->shop, $this->consumer, (string) Str::uuid(), [
            'external_id' => 'DM-AUD', 'order_amount_minor' => 5000, 'commission_minor' => 1000,
        ]);
        $event = AuditEvent::where('action', 'cashback.direct_merchant_credited')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame((string) $row->id, $event->resource_id);
        $this->assertSame($this->consumer->id, $event->actor_user_id);
        $this->assertSame($this->shop->id, $event->webshop_id);
        $this->assertArrayHasKey('commission_minor', $event->metadata);
        $this->assertArrayHasKey('ledger', $event->metadata);
        $this->assertStringNotContainsString('password', strtolower(json_encode($event->metadata)));
    }

    /** 7. Direct merchant included in scoped reconciliation run coverage. */
    public function test_07_direct_merchant_source_covered_by_reconciliation_run(): void
    {
        MerchantCommissionAgreement::create([
            'webshop_id' => $this->shop->id, 'version' => 1, 'commission_type' => 'fixed',
            'commission_fixed_minor' => 1000, 'consumer_share_bps' => 7000, 'shopbonus_share_bps' => 3000,
            'cashback_approved' => true, 'active' => true, 'created_by' => $this->admin->id,
        ]);
        $row = app(DirectMerchantCashback::class)->creditVerifiedConversion($this->shop, $this->consumer, (string) Str::uuid(), [
            'external_id' => 'DM-RUN', 'order_amount_minor' => 5000, 'commission_minor' => 1000,
        ]);
        $entry = WalletLedgerEntry::findOrFail($row->wallet_entry_id);
        $this->assertSame($row->public_id, $entry->source_ref);
        $this->assertSame('matched', app(FinancialSources::class)->inspect($entry)['status']);

        $runner = app(FinancialReconciliation::class);
        $run = $runner->start('dm:coverage');
        $this->assertSame(3, $run->scope_version);
        $this->assertArrayHasKey('direct_merchant', $run->source_state);
        do {
            $run = $runner->advance($run, 100);
        } while ($run->status === 'running' || $run->status === 'pending');
        $this->assertSame('completed', $run->status);
        $this->assertSame('OBSERVED', $runner->summary($run)['coverage']['direct_merchant']);
        $this->assertTrue(
            FinancialReconciliationCheck::where('run_id', $run->id)->where('source_type', 'direct_merchant')
                ->where('source_ref', $row->public_id)->where('status', 'matched')->exists()
        );
    }

    /** 8. Consumer cannot read another consumer's wallet; voucher codes stay masked. */
    public function test_08_consumer_wallet_isolation(): void
    {
        $other = User::factory()->create(['email_verified_at' => now()]);
        $wallets = app(WalletService::class);
        $wallets->credit($wallets->consumerWallet($other), 500, 'other-seed');
        $this->actingAs($this->consumer);
        $this->getJson('/api/v1/consumer/wallet')->assertOk()
            ->assertJsonPath('data.available_minor', 0);
        $this->assertSame(500, $wallets->consumerWallet($other)->fresh()->available);
    }

    /** 9. Duplicate direct-merchant idempotency + reconciliation detects forged second credit. */
    public function test_09_direct_merchant_duplicate_and_forged_credit_detection(): void
    {
        MerchantCommissionAgreement::create([
            'webshop_id' => $this->shop->id, 'version' => 1, 'commission_type' => 'fixed',
            'commission_fixed_minor' => 1000, 'consumer_share_bps' => 7000, 'shopbonus_share_bps' => 3000,
            'cashback_approved' => true, 'active' => true, 'created_by' => $this->admin->id,
        ]);
        $key = (string) Str::uuid();
        $cashback = app(DirectMerchantCashback::class);
        $a = $cashback->creditVerifiedConversion($this->shop, $this->consumer, $key, [
            'external_id' => 'DM-ONCE', 'order_amount_minor' => 5000, 'commission_minor' => 1000,
        ]);
        $b = $cashback->creditVerifiedConversion($this->shop, $this->consumer, $key, [
            'external_id' => 'DM-ONCE', 'order_amount_minor' => 5000, 'commission_minor' => 1000,
        ]);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, MerchantCommissionConversion::where('external_id', 'DM-ONCE')->count());
        $this->assertSame(1, WalletLedgerEntry::where('source_type', 'direct_merchant')->where('type', 'credit')->count());

        $wallet = app(WalletService::class)->consumerWallet($this->consumer);
        app(WalletService::class)->credit($wallet, 700, 'forged-dm', meta: [
            'source_type' => 'direct_merchant', 'source_ref' => $a->public_id, 'reason' => 'forged',
        ]);
        $check = app(FinancialSources::class)->inspect($a->fresh());
        $this->assertSame('discrepancy', $check['status']);
        $this->assertContains('direct_merchant_duplicate_credit', $check['evidence']['issues']);
    }

    /** 10. Failed reconciliation resume does not duplicate committed checks; overview stays truthful. */
    public function test_10_failed_run_resume_and_disaster_status_truthfulness(): void
    {
        $runner = app(FinancialReconciliation::class);
        $run = $runner->start('disaster:resume');
        $run->forceFill(['status' => 'failed', 'failure_class' => 'RuntimeException'])->save();
        $runner->resume($run);
        do {
            $run = $runner->advance($run->fresh(), 100);
        } while (in_array($run->status, ['running', 'pending'], true));
        $this->assertSame('completed', $run->status);
        $dupes = FinancialReconciliationCheck::query()
            ->select('source_type', 'source_ref', DB::raw('count(*) as c'))
            ->where('run_id', $run->id)->groupBy('source_type', 'source_ref')->having('c', '>', 1)->count();
        $this->assertSame(0, $dupes);
        $this->assertSame('UNKNOWN', app(Operations::class)->report()['components']['backups']['status']);
        $this->assertSame('UNKNOWN', app(Operations::class)->report()['components']['security']['status']);
    }

    private function seedProgram(): void
    {
        AffiliateProgram::create([
            'connection_id' => $this->daisycon->id, 'external_id' => 'P1', 'name' => 'Reliability Shop',
            'domain' => 'example.com', 'webshop_id' => $this->shop->id, 'active' => true,
            'commission_type' => 'percentage', 'commission_value' => 1000, 'currency' => 'EUR',
            'category_restricted' => false, 'cashback_allowed' => true,
            'tracking_url_template' => 'https://lt45.net/c/?r={click_ref}',
        ]);
    }

    private function click(): string
    {
        $program = AffiliateProgram::where('external_id', 'P1')->sole();
        $reference = 'CLICK'.strtoupper(Str::random(16));
        AffiliateClick::create([
            'reference' => $reference,
            'user_id' => $this->consumer->id,
            'webshop_id' => $this->shop->id,
            'program_id' => $program->id,
        ]);

        return $reference;
    }
}
