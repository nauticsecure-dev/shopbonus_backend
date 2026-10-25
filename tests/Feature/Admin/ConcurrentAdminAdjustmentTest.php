<?php

namespace Tests\Feature\Admin;

use App\Jobs\AnalyzeIncident;
use App\Models\AdminApprovalRequest;
use App\Models\IncidentDiagnostic;
use App\Models\PlatformSettingVersion;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Admin\Diagnostics\IncidentDiagnostics;
use App\Services\Admin\PlatformSettings;
use App\Services\Admin\WalletAdjustments;
use App\Services\Audit\AuditIntegrity;
use App\Services\Audit\AuditLogger;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

/**
 * Trello #19 E2E 8 / #23 test 7: concurrent spends against one wallet, in
 * real processes on MySQL. Exactly the affordable number succeed, the
 * balance is exact and never negative, and the ledger reconciles.
 */
class ConcurrentAdminAdjustmentTest extends TestCase
{
    use DatabaseTruncation, RacesProcesses {
        DatabaseTruncation::truncateDatabaseTables as truncateCommittedTables;
    }

    /**
     * On SQLite these tests are skipped; truncating would mark migrations as
     * done and leave later in-memory RefreshDatabase tests without tables.
     */
    protected function truncateDatabaseTables(): void
    {
        if (in_array(config('database.default'), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->truncateCommittedTables();
        }
    }

    protected function tearDown(): void
    {
        $this->truncateAfterRace();
        parent::tearDown();
    }

    public function test_simultaneous_diagnostic_requests_reserve_one_global_quota_slot(): void
    {
        $this->skipUnlessRealDatabase();
        config(['diagnostics.enabled' => true, 'diagnostics.api_key' => 'fixture-key', 'diagnostics.model' => 'fixture-model', 'diagnostics.daily_request_limit' => 1]);
        Bus::fake([AnalyzeIncident::class]);
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        $incident = SecurityIncident::create(['fingerprint' => hash('sha256', 'diagnostic-race'), 'category' => 'application', 'severity' => 'error',
            'service' => 'api', 'http_status' => 500, 'status' => 'new', 'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $results = $this->race(2, fn (int $worker) => 'ok:'.app(IncidentDiagnostics::class)->request(
            SecurityIncident::findOrFail($incident->id), User::findOrFail($admin->id), 'race-diagnostic-'.$worker)->public_id);
        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok:'))), implode(', ', $results));
        $this->assertContains('err:diagnostic_daily_limit', $results);
        $this->assertSame(1, IncidentDiagnostic::count());
        $this->assertSame(1, DB::table('incident_diagnostic_quotas')->value('requests'));
    }

    public function test_concurrent_review_posts_exactly_one_adjustment(): void
    {
        $this->skipUnlessRealDatabase();
        $admins = User::factory()->count(3)->create();
        foreach ($admins as $admin) {
            $admin->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        }
        $consumer = User::factory()->create();
        $wallet = app(WalletService::class)->consumerWallet($consumer);
        $source = app(WalletService::class)->credit($wallet, 500, 'original');
        $request = app(WalletAdjustments::class)->request($admins[0], $wallet, $consumer, $source, 100,
            'Missing credit verified', 'Identity and source checked', 'race-request');
        $results = $this->race(2, fn (int $w) => 'ok:'.app(WalletAdjustments::class)->review(
            AdminApprovalRequest::findOrFail($request->id), User::findOrFail($admins[$w + 1]->id), 'approved', 'Independently checked')->public_id);
        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok:'))), implode(', ', $results));
        $this->assertContains('err:approval_already_reviewed', $results);
        $this->assertSame(600, $wallet->fresh()->available);
        $this->assertSame(2, $wallet->entries()->count());
        $this->assertSame([], app(WalletService::class)->reconcile());
    }

    public function test_concurrent_audit_appends_form_one_complete_chain(): void
    {
        $this->skipUnlessRealDatabase();
        $results = $this->race(8, fn (int $worker) => 'ok:'.app(AuditLogger::class)->record('race.audit.'.$worker)->id);
        $this->assertSame(8, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok:'))), implode(', ', $results));
        $result = app(AuditIntegrity::class)->verify();
        $this->assertTrue($result['valid']);
        $this->assertSame(8, $result['entries']);
    }

    public function test_concurrent_setting_publish_has_one_winner_and_one_current_version(): void
    {
        $this->skipUnlessRealDatabase();
        $author = User::factory()->create();
        $reviewers = User::factory()->count(2)->create();
        foreach ($reviewers->push($author) as $user) {
            $user->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        }
        $settings = app(PlatformSettings::class);
        $version = $settings->draft($author, 'public.brand_name', 'Shopbonus NL', 'Verified branding change');
        $settings->submit($version, $author);
        $results = $this->race(2, fn (int $worker) => 'ok:'.app(PlatformSettings::class)->publish(
            PlatformSettingVersion::findOrFail($version->id), User::findOrFail($reviewers[$worker]->id), 'Independent review')->public_id);
        $this->assertSame(1, count(array_filter($results, fn ($r) => str_starts_with($r, 'ok:'))), implode(', ', $results));
        $this->assertContains('err:setting_not_in_review', $results);
        $this->assertSame($version->public_id, $settings->published()['versions']['public.brand_name']);
        $this->assertTrue(app(AuditIntegrity::class)->verify()['valid']);
    }
}
