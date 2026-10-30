<?php

namespace Tests\Feature\Qa;

use App\Enums\PlatformRole;
use App\Models\AuditEvent;
use App\Models\QaEndpoint;
use App\Models\QaFinding;
use App\Models\QaTestCase;
use App\Models\QaTestRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * B30: Quality Center backend (Trello #37) — discovery, approved coverage,
 * JUnit import and status rules, private evidence, findings with independent
 * retest, advisory AI analysis (provider faked), schedules and the protected
 * OpenAPI contract. Executing the 25 PDF journeys against staging/providers is
 * not part of this test.
 */
class QualityCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $qa;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = $this->withRole(PlatformRole::SuperAdmin);
        $this->qa = $this->withRole(PlatformRole::QaTester);
        $this->dev = $this->withRole(PlatformRole::Developer);
    }

    private function withRole(PlatformRole $role): User
    {
        $u = User::factory()->create();
        $u->platformRoles()->create(['role' => $role, 'granted_via' => 'console', 'granted_at' => now()]);

        return $u;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function junit(array $cases): UploadedFile
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="'.uniqid('run-', true).'">';
        foreach ($cases as [$class, $name, $assertions, $child]) {
            $xml .= '<testcase class="'.$class.'" classname="'.$class.'" name="'.$name.'" assertions="'.$assertions.'" time="0.012">'.$child.'</testcase>';
        }

        return UploadedFile::fake()->createWithContent('junit.xml', $xml.'</testsuite></testsuites>');
    }

    private function import(array $cases, string $sha = 'abc1234', array $extra = []): string
    {
        return $this->as($this->qa)->post('/api/v1/admin/qa/runs', ['report' => $this->junit($cases), 'suite' => 'full', 'environment' => 'staging', 'git_sha' => $sha] + $extra)
            ->assertCreated()->json('data.id');
    }

    private function endpointId(string $method, string $path): string
    {
        return QaEndpoint::where('method', $method)->where('path', $path)->value('public_id');
    }

    public function test_roles_are_separated(): void
    {
        $consumer = User::factory()->create();
        $this->as($consumer)->getJson('/api/v1/admin/qa/summary')->assertForbidden()->assertJsonPath('error.code', 'platform_role_required');
        $this->as($consumer)->getJson('/api/v1/admin/qa/openapi.json')->assertForbidden();
        $this->as($this->dev)->getJson('/api/v1/admin/qa/summary')->assertForbidden()->assertJsonPath('error.code', 'platform_role_required');
        $this->as($this->dev)->getJson('/api/v1/admin/qa/findings')->assertOk();
        $this->as($this->dev)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->as($this->qa)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->as($this->qa)->getJson('/api/v1/admin/qa/summary')->assertOk()->assertJsonPath('data.endpoints', 0);
        $this->as($this->qa)->postJson('/api/v1/admin/qa/schedules', ['name' => 'n', 'suite' => 'full', 'environment' => 'staging', 'trigger' => 'nightly'])
            ->assertForbidden();

        // MFA applies to the new roles too.
        config(['shopbonus.admin.mfa_required' => true]);
        $this->as($this->qa)->getJson('/api/v1/admin/qa/summary')->assertForbidden()->assertJsonPath('error.code', 'mfa_required');
    }

    public function test_discovery_finds_new_changed_removed_and_undocumented_routes(): void
    {
        $this->as($this->qa)->postJson('/api/v1/admin/qa/inventory/scan', ['git_sha' => 'nothex'])->assertUnprocessable();
        $first = $this->as($this->qa)->postJson('/api/v1/admin/qa/inventory/scan', ['git_sha' => 'aaaaaaa'])->assertCreated();
        $count = QaEndpoint::count();
        $this->assertGreaterThan(300, $count);
        $this->assertSame($count, $first->json('data.totals.endpoints'));
        $this->assertSame(0, $first->json('data.totals.undocumented'), 'every real route is documented');
        $this->assertGreaterThan(0, $first->json('data.totals.job'));
        $this->assertGreaterThan(0, $first->json('data.totals.schedule'));
        $this->assertGreaterThan(0, $first->json('data.totals.connector'));
        $wallet = QaEndpoint::where('path', '/api/v1/consumer/wallet')->where('method', 'GET')->firstOrFail();
        $this->assertTrue($wallet->documented);
        $this->assertNotNull($wallet->summary);

        // E2E-01: deploy a new endpoint → detected, undocumented and UNTESTED.
        Route::middleware('api')->get('api/v1/qa-discovery-probe', fn () => ['ok' => true]);
        $second = $this->as($this->qa)->postJson('/api/v1/admin/qa/inventory/scan', ['git_sha' => 'bbbbbbb'])->assertCreated();
        $this->assertSame(['GET /api/v1/qa-discovery-probe'], $second->json('data.diff.endpoints.added'));
        $this->assertSame([], $second->json('data.diff.endpoints.changed'));
        $this->assertSame(['GET /api/v1/qa-discovery-probe'], $second->json('data.diff.undocumented'));
        $new = $this->as($this->qa)->getJson('/api/v1/admin/qa/endpoints?filter=new')->assertOk();
        $this->assertSame(1, $new->json('meta.total'));
        $new->assertJsonPath('data.0.status', 'untested')->assertJsonPath('data.0.documented', false)->assertJsonPath('data.0.first_seen_sha', 'bbbbbbb');
        $this->as($this->qa)->getJson('/api/v1/admin/qa/endpoints?filter=undocumented')->assertJsonPath('meta.total', 1);

        // A changed handler/middleware changes the fingerprint → "changed".
        QaEndpoint::where('path', '/api/v1/consumer/wallet')->update(['fingerprint' => str_repeat('0', 64)]);
        $third = $this->as($this->qa)->postJson('/api/v1/admin/qa/inventory/scan', ['git_sha' => 'ccccccc'])->assertCreated();
        $this->assertSame(['GET /api/v1/consumer/wallet'], $third->json('data.diff.endpoints.changed'));
        $this->assertSame('ccccccc', $wallet->fresh()->changed_sha);
        $this->assertSame('aaaaaaa', $wallet->fresh()->first_seen_sha);

        // Removed routes are kept with removed_at, never deleted.
        QaEndpoint::create(['method' => 'GET', 'path' => '/api/v1/gone', 'access' => 'public', 'scope' => 'public', 'documented' => true,
            'fingerprint' => 'x', 'first_seen_sha' => 'aaaaaaa', 'changed_sha' => 'aaaaaaa', 'changed_at' => now()]);
        $fourth = $this->as($this->qa)->postJson('/api/v1/admin/qa/inventory/scan', ['git_sha' => 'ddddddd'])->assertCreated();
        $this->assertSame(['GET /api/v1/gone'], $fourth->json('data.diff.endpoints.removed'));
        $this->assertNotNull(QaEndpoint::where('path', '/api/v1/gone')->value('removed_at'));
        $this->as($this->qa)->getJson('/api/v1/admin/qa/endpoints?filter=removed')->assertJsonPath('data.0.status', 'removed');
        $this->assertSame(4, AuditEvent::where('action', 'qa.inventory_scanned')->count());

        $this->artisan('shopbonus:qa-discover', ['--sha' => 'eeeeeee'])->assertSuccessful();
        $this->artisan('shopbonus:qa-discover', ['--sha' => 'not a sha'])->assertFailed();
    }

    public function test_status_comes_only_from_approved_executed_evidence(): void
    {
        $this->artisan('shopbonus:qa-discover', ['--sha' => 'aaaaaaa'])->assertSuccessful();
        $wallet = $this->endpointId('GET', '/api/v1/consumer/wallet');
        $profile = $this->endpointId('GET', '/api/v1/consumer/profile');
        $run = $this->import([
            ['Tests\\WalletTest', 'test_balance', 5, ''],
            ['Tests\\WalletTest', 'test_no_assertions', 0, ''],
            ['Tests\\ProfileTest', 'test_profile', 3, '<failure message="Expected 200, got 500">trace</failure>'],
            ['Tests\\ProviderTest', 'test_mollie', 0, '<skipped message="BLOCKED: Mollie TEST key not configured"/>'],
            ['Tests\\ProviderTest', 'test_moneybird', 0, '<skipped message="NOT_IMPLEMENTED: Moneybird sync"/>'],
            ['Tests\\ProviderTest', 'test_contact_mocked', 2, ''],
        ]);
        $detail = $this->as($this->qa)->getJson("/api/v1/admin/qa/runs/{$run}")->assertOk();
        $detail->assertJsonPath('data.totals.pass', 2)->assertJsonPath('data.totals.fail', 1)->assertJsonPath('data.totals.blocked', 1)
            ->assertJsonPath('data.totals.not_implemented', 1)->assertJsonPath('data.totals.skipped', 1)->assertJsonPath('data.totals.mocked', 1)
            ->assertJsonPath('data.totals.assertions', 10);
        $this->assertSame('No assertions executed; not counted as PASS.', collect($detail->json('data.results'))->firstWhere('key', 'Tests\\WalletTest::test_no_assertions')['message']);
        $this->assertSame(6, QaTestCase::where('status', 'draft')->count());

        // Duplicate report: never imported twice.
        $report = $this->junit([['Tests\\Dup', 'test_dup', 1, '']]);
        $bytes = (string) file_get_contents($report->getPathname());
        $this->as($this->qa)->post('/api/v1/admin/qa/runs', ['report' => $report, 'suite' => 'full', 'environment' => 'staging', 'git_sha' => 'abc1234'])->assertCreated();
        $this->as($this->qa)->post('/api/v1/admin/qa/runs', ['report' => UploadedFile::fake()->createWithContent('again.xml', $bytes), 'suite' => 'full', 'environment' => 'staging', 'git_sha' => 'abc1234'])
            ->assertConflict()->assertJsonPath('error.code', 'run_duplicate');
        $xxe = UploadedFile::fake()->createWithContent('x.xml', '<?xml version="1.0"?><!DOCTYPE t [<!ENTITY e SYSTEM "file:///etc/passwd">]><testsuite><testcase name="&e;"/></testsuite>');
        $this->as($this->qa)->post('/api/v1/admin/qa/runs', ['report' => $xxe, 'suite' => 'full', 'environment' => 'staging', 'git_sha' => 'abc1234'])->assertUnprocessable();

        // Coverage links alone do not count while the cases are drafts.
        $this->as($this->qa)->postJson('/api/v1/admin/qa/coverage', ['map' => [
            'Tests\\WalletTest::test_balance' => ['GET /api/v1/consumer/wallet', 'GET /api/v1/unknown'],
            'Tests\\ProfileTest::test_profile' => ['GET /api/v1/consumer/profile'], 'Tests\\Nope::x' => ['GET /api/v1/consumer/wallet'],
        ]])->assertOk()->assertJsonPath('data.linked', 2)->assertJsonPath('data.unknown_tests', ['Tests\\Nope::x'])->assertJsonPath('data.unknown_targets', ['GET /api/v1/unknown']);
        $this->as($this->qa)->getJson("/api/v1/admin/qa/endpoints/{$wallet}")->assertJsonPath('data.status', 'untested');

        $this->as($this->qa)->postJson('/api/v1/admin/qa/test-cases/approve', ['keys' => ['Tests\\WalletTest::test_balance', 'Tests\\ProfileTest::test_profile', 'missing']])
            ->assertOk()->assertJsonPath('data.approved', 2)->assertJsonPath('data.skipped', 1);
        $this->as($this->qa)->getJson("/api/v1/admin/qa/endpoints/{$wallet}")->assertJsonPath('data.status', 'pass')->assertJsonPath('data.mocked', false)
            ->assertJsonPath('data.test_cases.0.latest_result.status', 'pass');
        $this->as($this->qa)->getJson("/api/v1/admin/qa/endpoints/{$profile}")->assertJsonPath('data.status', 'fail');
        $this->as($this->qa)->getJson('/api/v1/admin/qa/endpoints?filter=failed')->assertJsonPath('meta.total', 1);

        // Manual coverage for a mocked test → PASS stays labelled MOCKED.
        $case = QaTestCase::where('key', 'Tests\\ProviderTest::test_contact_mocked')->firstOrFail();
        $vouchers = $this->endpointId('GET', '/api/v1/consumer/vouchers');
        $this->as($this->qa)->putJson("/api/v1/admin/qa/test-cases/{$case->public_id}/coverage", ['endpoints' => [$vouchers, 'nope']])->assertUnprocessable();
        $this->as($this->qa)->putJson("/api/v1/admin/qa/test-cases/{$case->public_id}/coverage", ['endpoints' => [$vouchers]])->assertOk();
        $this->as($this->qa)->postJson('/api/v1/admin/qa/test-cases/approve', ['keys' => [$case->key]])->assertOk();
        $this->as($this->qa)->getJson("/api/v1/admin/qa/endpoints/{$vouchers}")->assertJsonPath('data.status', 'pass')->assertJsonPath('data.mocked', true);

        // A code change after the evidence makes it STALE, never PASS.
        QaEndpoint::where('public_id', $wallet)->update(['changed_at' => now()->addMinute()]);
        $this->as($this->qa)->getJson("/api/v1/admin/qa/endpoints/{$wallet}")->assertJsonPath('data.status', 'stale');
        $this->travel(8)->days();
        QaEndpoint::where('public_id', $wallet)->update(['changed_at' => now()->subDays(30)]);
        $this->as($this->qa)->getJson("/api/v1/admin/qa/endpoints/{$wallet}")->assertJsonPath('data.status', 'stale');

        $summary = $this->as($this->qa)->getJson('/api/v1/admin/qa/summary')->assertOk();
        // Eight days later all three covered endpoints are STALE (including the failure), none PASS.
        $summary->assertJsonPath('data.fail', 0)->assertJsonPath('data.stale', 3)->assertJsonPath('data.pass', 0)->assertJsonPath('data.last_run.suite', 'full');
        $this->assertSame($summary->json('data.endpoints') - 3, $summary->json('data.untested'));
    }

    public function test_exports_are_escaped_private_downloads(): void
    {
        $run = $this->import([['Tests\\X', 'test_&lt;script&gt;', 1, ''], ['Tests\\X', 'test_fail', 1, '<failure message="bad &lt;b&gt; for jan@example.com">Bearer abc.def</failure>']]);
        $json = $this->as($this->qa)->get("/api/v1/admin/qa/runs/{$run}/export?format=json")->assertOk();
        $this->assertStringContainsString('application/json', $json->headers->get('Content-Type'));
        $this->assertSame('no-store, private', $json->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('jan@example.com', $json->getContent());
        $this->assertStringNotContainsString('abc.def', $json->getContent());
        $junit = $this->as($this->qa)->get("/api/v1/admin/qa/runs/{$run}/export?format=junit")->assertOk();
        $this->assertNotFalse(simplexml_load_string($junit->getContent()));
        $html = $this->as($this->qa)->get("/api/v1/admin/qa/runs/{$run}/export?format=html")->assertOk();
        $this->assertStringNotContainsString('<script>', $html->getContent());
        $this->assertStringContainsString('noindex', $html->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('attachment', $html->headers->get('Content-Disposition'));
        $this->as($this->qa)->getJson("/api/v1/admin/qa/runs/{$run}/export?format=pdf")->assertUnprocessable();
    }

    public function test_evidence_is_encrypted_deduplicated_and_scoped(): void
    {
        $run = $this->import([['Tests\\Pay', 'test_split', 4, '<failure message="ledger off by 1 cent"/>']]);
        $png = UploadedFile::fake()->image('proof.png', 20, 20);
        $bytes = (string) file_get_contents($png->getPathname());
        $first = $this->as($this->qa)->post("/api/v1/admin/qa/runs/{$run}/evidence", ['file' => $png, 'request_id' => 'req-123'])->assertCreated();
        $id = $first->json('data.id');
        $this->assertSame(hash('sha256', $bytes), $first->json('data.sha256'));
        $stored = Storage::disk('local')->allFiles('qa-evidence');
        $this->assertCount(1, $stored);
        $this->assertStringNotContainsString($bytes, (string) Storage::disk('local')->get($stored[0]));
        $again = UploadedFile::fake()->createWithContent('proof-copy.png', $bytes);
        $this->as($this->qa)->post("/api/v1/admin/qa/runs/{$run}/evidence", ['file' => $again])->assertOk()->assertJsonPath('data.id', $id);
        $this->as($this->qa)->post("/api/v1/admin/qa/runs/{$run}/evidence", ['file' => UploadedFile::fake()->createWithContent('x.html', '<html><script>1</script></html>')])
            ->assertUnprocessable();

        $download = $this->as($this->admin)->get("/api/v1/admin/qa/evidence/{$id}")->assertOk();
        $this->assertSame($bytes, $download->getContent());
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));

        // Developer: only evidence of findings assigned to them.
        $this->as($this->dev)->get("/api/v1/admin/qa/evidence/{$id}")->assertNotFound();
        $this->as($this->qa)->postJson('/api/v1/admin/qa/findings', ['title' => 'Split payment ledger', 'severity' => 'p0', 'run_id' => $run,
            'test_keys' => ['Tests\\Pay::test_split'], 'owner_id' => $this->dev->public_id])->assertCreated();
        $this->as($this->dev)->get("/api/v1/admin/qa/evidence/{$id}")->assertOk();
        $this->assertSame(2, AuditEvent::where('action', 'qa.evidence_downloaded')->count());

        // Tampered file → refused, not served.
        Storage::disk('local')->put($stored[0], encrypt('x'));
        $this->as($this->admin)->get("/api/v1/admin/qa/evidence/{$id}")->assertStatus(410);
    }

    public function test_finding_workflow_needs_an_independent_evidence_based_retest(): void
    {
        $this->artisan('shopbonus:qa-discover', ['--sha' => 'aaaaaaa'])->assertSuccessful();
        $failing = $this->import([['Tests\\Claim', 'test_last_code', 6, '<failure message="two vouchers issued"/>']]);
        $other = $this->withRole(PlatformRole::Developer);
        $this->as($this->qa)->postJson('/api/v1/admin/qa/findings', ['title' => 'x', 'severity' => 'p1', 'owner_id' => $this->qa->public_id])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->as($this->qa)->postJson('/api/v1/admin/qa/findings', ['title' => 'x', 'severity' => 'p1', 'affected_routes' => ['GET /api/v1/nope']])->assertUnprocessable();
        $body = ['title' => 'Last code issued twice', 'severity' => 'p0', 'run_id' => $failing, 'owner_id' => $this->dev->public_id,
            'affected_routes' => ['POST /api/v1/claims/accept'], 'test_keys' => ['Tests\\Claim::test_last_code'], 'reproduction' => 'Two parallel claims; mail jan@example.com'];
        $finding = $this->as($this->qa)->postJson('/api/v1/admin/qa/findings', $body)->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
        $this->as($this->qa)->postJson('/api/v1/admin/qa/findings', ['title' => 'Same defect, other words'] + $body)->assertConflict()->assertJsonPath('error.code', 'finding_exists');

        $this->as($other)->getJson('/api/v1/admin/qa/findings')->assertJsonPath('meta.total', 0);
        $this->as($other)->getJson("/api/v1/admin/qa/findings/{$finding}")->assertNotFound();
        $this->as($other)->postJson("/api/v1/admin/qa/findings/{$finding}/fix", ['fix_commit' => 'abcdef1'])->assertNotFound();
        $this->as($this->dev)->getJson('/api/v1/admin/qa/findings')->assertJsonPath('meta.total', 1);
        $this->as($this->dev)->postJson("/api/v1/admin/qa/findings/{$finding}/retest", ['run_id' => $failing])->assertForbidden();
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/fix", ['fix_commit' => 'abcdef1'])->assertForbidden();
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/retest", ['run_id' => $failing])->assertConflict()->assertJsonPath('error.code', 'finding_not_fixed');

        $this->travel(1)->minutes();
        $this->as($this->dev)->postJson("/api/v1/admin/qa/findings/{$finding}/fix", ['fix_commit' => 'zzz'])->assertUnprocessable();
        $this->as($this->dev)->postJson("/api/v1/admin/qa/findings/{$finding}/fix", ['fix_commit' => 'ABCDEF1234'])->assertOk()
            ->assertJsonPath('data.status', 'fixed')->assertJsonPath('data.fix_commit', 'abcdef1234')->assertJsonPath('data.fixed_by', $this->dev->public_id);
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/retest", ['run_id' => $failing])->assertConflict()->assertJsonPath('error.code', 'retest_run_outdated');

        // Retest that still fails → retest_failed, back to the developer.
        $this->travel(1)->minutes();
        $stillFailing = $this->import([['Tests\\Claim', 'test_last_code', 6, '<failure message="still two"/>']], 'abcdef1234');
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/retest", ['run_id' => $stillFailing, 'passed' => true])->assertOk()
            ->assertJsonPath('data.status', 'retest_failed');
        $this->as($this->dev)->postJson("/api/v1/admin/qa/findings/{$finding}/fix", ['fix_commit' => 'bcdef12'])->assertOk();

        // A mocked pass is not proof.
        $this->travel(1)->minutes();
        $mocked = $this->import([['Tests\\Claim', 'test_last_code', 6, '']], 'bcdef12', ['mocked' => 1]);
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/retest", ['run_id' => $mocked])->assertOk()->assertJsonPath('data.status', 'retest_failed');
        $this->as($this->dev)->postJson("/api/v1/admin/qa/findings/{$finding}/fix", ['fix_commit' => 'cdef123'])->assertOk();

        // The fixer cannot retest their own fix (Super Admin fixing then retesting).
        $this->as($this->admin)->postJson("/api/v1/admin/qa/findings/{$finding}/fix", ['fix_commit' => 'cdef123'])->assertConflict();
        $this->travel(1)->minutes();
        $passing = $this->import([['Tests\\Claim', 'test_last_code', 6, '']], 'cdef123');
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/retest", ['run_id' => $passing])->assertOk()
            ->assertJsonPath('data.status', 'retest_passed')->assertJsonPath('data.retested_by', $this->qa->public_id)->assertJsonPath('data.retest_run_id', $passing);
        $this->assertSame(1, AuditEvent::where('action', 'qa.finding_created')->count());
        $this->assertSame(3, AuditEvent::where('action', 'qa.finding_retested')->count());

        $selfFix = QaFinding::create(['fingerprint' => 'f', 'title' => 't', 'severity' => 'p2', 'status' => 'open', 'affected_routes' => [], 'test_keys' => [],
            'owner_id' => $this->admin->id, 'created_by' => $this->qa->id]);
        $this->as($this->admin)->postJson("/api/v1/admin/qa/findings/{$selfFix->public_id}/fix", ['fix_commit' => 'abcdef1'])->assertOk();
        $this->travel(1)->minutes();
        $any = $this->import([['Tests\\Y', 'test_y', 1, '']], 'abcdef1');
        $this->as($this->admin)->postJson("/api/v1/admin/qa/findings/{$selfFix->public_id}/retest", ['run_id' => $any, 'passed' => true])
            ->assertForbidden()->assertJsonPath('error.code', 'retest_not_independent');
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$selfFix->public_id}/retest", ['run_id' => $any])->assertUnprocessable();
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$selfFix->public_id}/retest", ['run_id' => $any, 'passed' => true])->assertJsonPath('data.status', 'retest_passed');
    }

    private function fakeModel(array $analysis): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($analysis)]]],
        ]])]);
    }

    public function test_ai_analysis_is_advisory_and_grounded_in_the_input(): void
    {
        $this->artisan('shopbonus:qa-discover', ['--sha' => 'aaaaaaa'])->assertSuccessful();
        $run = $this->import([['Tests\\Claim', 'test_last_code', 6, '<failure message="two vouchers for jan@example.com">x</failure>']]);
        $finding = $this->as($this->qa)->postJson('/api/v1/admin/qa/findings', ['title' => 'Duplicate voucher', 'severity' => 'p1', 'run_id' => $run,
            'affected_routes' => ['POST /api/v1/claims/accept'], 'test_keys' => ['Tests\\Claim::test_last_code']])->json('data.id');
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/analyze")->assertStatus(503)->assertJsonPath('error.code', 'qa_ai_not_configured');

        config(['qa.ai_enabled' => true, 'qa.api_key' => 'sk-test-qa', 'qa.model' => 'gpt-test', 'qa.daily_limit' => 3]);
        $good = ['affected_routes' => ['POST /api/v1/claims/accept'], 'test_ids' => ['Tests\\Claim::test_last_code'], 'severity' => 'p0', 'evidence_ids' => [],
            'expected' => 'One voucher', 'actual' => 'Two vouchers', 'hypothesis' => 'Missing row lock on the last code', 'needs_review' => false, 'confidence' => 0.8,
            'proposed_tests' => ['Two parallel claims on the last code issue exactly one voucher']];
        $this->fakeModel(['affected_routes' => ['DELETE /api/v1/everything']] + $good);
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/analyze")->assertStatus(503)->assertJsonPath('error.code', 'qa_analysis_failed');
        $this->assertSame('failed', QaFinding::where('public_id', $finding)->value('ai_status'));

        $this->fakeModel($good);
        $res = $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/analyze")->assertOk();
        $res->assertJsonPath('data.ai_status', 'proposed')->assertJsonPath('data.ai_analysis.hypothesis', 'Missing row lock on the last code')
            ->assertJsonPath('data.ai_analysis.needs_review', true)->assertJsonPath('data.ai_analysis.advisory', true)
            ->assertJsonPath('data.severity', 'p1')->assertJsonPath('data.status', 'open');
        $this->assertSame(1, QaTestCase::where('source', 'ai')->where('status', 'draft')->count());
        Http::assertSent(function ($request) {
            $input = (string) $request['input'];

            return $request['store'] === false && ! str_contains($input, 'jan@example.com') && str_contains($input, 'Tests\\\\Claim::test_last_code');
        });

        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/analyze")->assertOk();
        $this->as($this->qa)->postJson("/api/v1/admin/qa/findings/{$finding}/analyze")->assertStatus(429)->assertJsonPath('error.code', 'qa_ai_daily_limit')->assertHeader('Retry-After');
    }

    public function test_schedules_probe_frontend_usage_and_openapi(): void
    {
        $this->as($this->admin)->postJson('/api/v1/admin/qa/schedules', ['name' => 'Prod full', 'suite' => 'full', 'environment' => 'production', 'trigger' => 'nightly'])
            ->assertUnprocessable();
        $prod = $this->as($this->admin)->postJson('/api/v1/admin/qa/schedules', ['name' => 'Prod health', 'suite' => 'safe', 'environment' => 'production', 'trigger' => 'every_5_minutes'])
            ->assertCreated()->assertJsonPath('data.executed_by', 'scheduler')->json('data.id');
        $this->as($this->admin)->postJson('/api/v1/admin/qa/schedules', ['name' => 'Nightly', 'suite' => 'full', 'environment' => 'staging', 'trigger' => 'nightly'])
            ->assertCreated()->assertJsonPath('data.executed_by', 'ci_runner');
        $this->as($this->admin)->patchJson("/api/v1/admin/qa/schedules/{$prod}", ['suite' => 'weekly'])->assertUnprocessable();
        $this->as($this->admin)->patchJson("/api/v1/admin/qa/schedules/{$prod}", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->as($this->qa)->getJson('/api/v1/admin/qa/schedules')->assertOk()->assertJsonCount(2, 'data');
        $this->assertNotNull($this->as($this->qa)->getJson('/api/v1/admin/qa/summary')->json('data.next_run_at'));

        $this->artisan('shopbonus:qa-probe')->expectsOutputToContain('disabled')->assertSuccessful();
        config(['qa.probe_enabled' => true, 'qa.release_sha' => 'abcdef1']);
        $this->artisan('shopbonus:qa-probe')->assertSuccessful();
        $probe = QaTestRun::where('source', 'probe')->firstOrFail();
        $this->assertSame(0, $probe->totals['fail']);
        $this->assertSame('safe', $probe->suite);
        $this->as($this->qa)->getJson('/api/v1/admin/qa/summary')->assertJsonPath('data.last_probe.healthy', true)->assertJsonPath('data.last_run', null);
        $this->travel(31)->days();
        $this->artisan('shopbonus:qa-probe', ['--prune' => true])->assertSuccessful();
        $this->assertSame(0, QaTestRun::where('source', 'probe')->count());

        $this->artisan('shopbonus:qa-discover', ['--sha' => 'aaaaaaa'])->assertSuccessful();
        $this->as($this->qa)->postJson('/api/v1/admin/qa/frontend-usage', ['routes' => ['GET /api/v1/consumer/wallet', 'rm -rf /']])->assertUnprocessable();
        $usage = $this->as($this->qa)->postJson('/api/v1/admin/qa/frontend-usage', ['routes' => ['GET /api/v1/consumer/wallet', 'GET /api/v1/never-existed']])->assertOk();
        $usage->assertJsonPath('data.referenced', 1)->assertJsonPath('data.unknown_routes', 1);
        $this->assertGreaterThan(0, $usage->json('data.external_clients'));
        $review = $this->as($this->qa)->getJson('/api/v1/admin/qa/endpoints?filter=unreferenced&per_page=100')->assertOk();
        $this->assertSame('UNREFERENCED — REVIEW', $review->json('data.0.review'));
        $this->assertFalse(collect($review->json('data'))->contains(fn ($e) => str_contains($e['path'], 'webhook')), 'webhook endpoints are not flagged');

        $spec = $this->as($this->qa)->get('/api/v1/admin/qa/openapi.json')->assertOk();
        $this->assertSame('noindex, nofollow', $spec->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', $spec->headers->get('Cache-Control'));
        $this->assertArrayHasKey('/api/v1/consumer/wallet', $spec->json('paths'));
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/admin/qa/openapi.json')->assertUnauthorized();
    }

    public function test_role_command_grants_and_revokes(): void
    {
        $u = User::factory()->create();
        $this->artisan('shopbonus:qa-role', ['email' => $u->email, 'role' => 'super_admin'])->assertFailed();
        $this->artisan('shopbonus:qa-role', ['email' => $u->email, 'role' => 'qa_tester'])->assertSuccessful();
        $this->assertTrue($u->fresh()->hasPlatformRole(PlatformRole::QaTester));
        $this->assertFalse($u->fresh()->isSuperAdmin());
        $this->artisan('shopbonus:qa-role', ['email' => $u->email, 'role' => 'qa_tester', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($u->fresh()->hasPlatformRole(PlatformRole::QaTester));
        $this->assertSame(1, AuditEvent::where('action', 'admin.qa_role_granted')->count());
    }

    public function test_cli_import_for_ci_runners(): void
    {
        $this->artisan('shopbonus:qa-discover', ['--sha' => 'aaaaaaa'])->assertSuccessful();
        $dir = sys_get_temp_dir().'/qa-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/junit.xml", '<testsuites><testsuite><testcase classname="Tests\\A" name="test_a" assertions="2" time="0.1"/></testsuite></testsuites>');
        file_put_contents("$dir/coverage.json", json_encode(['Tests\\A::test_a' => ['GET /api/v1/consumer/wallet']]));
        $this->artisan('shopbonus:qa-import', ['report' => "$dir/junit.xml", '--sha' => 'abcdef1', '--coverage' => "$dir/coverage.json"])->assertSuccessful();
        $this->artisan('shopbonus:qa-import', ['report' => "$dir/junit.xml", '--sha' => 'abcdef1'])->expectsOutputToContain('already imported')->assertFailed();
        $this->assertSame(1, QaTestRun::count());
        $this->assertSame(1, DB::table('qa_test_coverage')->count());

        // PHPUnit writes the PHP class in `class` and a dotted name in `classname`; keys use the PHP class.
        file_put_contents("$dir/dotted.xml", '<testsuites><testsuite><testcase class="Tests\\B" classname="Tests.B" name="test_b" assertions="1"/>'
            .'<testcase classname="Tests.C" name="test_c" assertions="1"/></testsuite></testsuites>');
        $this->artisan('shopbonus:qa-import', ['report' => "$dir/dotted.xml", '--sha' => 'abcdef1'])->assertSuccessful();
        $this->assertTrue(QaTestCase::where('key', 'Tests\\B::test_b')->exists());
        $this->assertTrue(QaTestCase::where('key', 'Tests\\C::test_c')->exists());
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
