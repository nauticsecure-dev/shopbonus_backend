<?php

namespace Tests\Feature\Admin;

use App\Exceptions\ApiException;
use App\Jobs\AnalyzeIncident;
use App\Models\IncidentDiagnostic;
use App\Models\IncidentDiagnosticResult;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentOccurrence;
use App\Models\User;
use App\Services\Admin\Diagnostics\IncidentDiagnostics;
use App\Services\Admin\PlatformSettings;
use App\Services\Admin\StepUpCodes;
use App\Services\Audit\AuditIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncidentDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private SecurityIncident $incident;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([AnalyzeIncident::class]);
        config(['diagnostics.enabled' => true, 'diagnostics.api_key' => 'sk-private-test-key', 'diagnostics.model' => 'fixture-model', 'diagnostics.daily_request_limit' => 10]);
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        $this->incident = SecurityIncident::create(['fingerprint' => hash('sha256', 'technical'), 'category' => 'application', 'severity' => 'error', 'service' => 'api',
            'route' => 'api/v1/consumer/wallet', 'http_method' => 'GET', 'http_status' => 500, 'status' => 'new', 'occurrences' => 5, 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    private function asAdmin(bool $password = true, bool $otp = true): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])->withSession([
            'auth.password_confirmed_at' => $password ? time() : 0, StepUpCodes::SESSION_KEY => $otp ? time() : 0]);
    }

    /** @return array<string, mixed> */
    private function analysis(): array
    {
        return ['likely_cause' => 'A server failure needs investigation.', 'evidence_refs' => ['E1', 'E5'], 'impacted_component' => 'api',
            'suggested_investigation' => ['Inspect authorized server logs locally.'], 'proposed_fix' => 'Investigate before proposing a production change.',
            'confidence' => 'low', 'uncertainty' => 'No stack trace or confirmed root cause is available.'];
    }

    /** @param array<string, mixed>|null $analysis
     * @return array<string, mixed>
     */
    private function response(?array $analysis = null): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($analysis ?? $this->analysis())]]]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 150]];
    }

    private function requestDiagnostic(string $key = 'diagnostic-fixture'): IncidentDiagnostic
    {
        return app(IncidentDiagnostics::class)->request($this->incident, $this->admin, $key);
    }

    public function test_requests_are_idempotent_and_execution_is_single_attempt_advisory_only(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->response())]);
        $this->asAdmin();
        $url = '/api/v1/admin/incidents/'.$this->incident->public_id.'/diagnostics';
        $id = $this->postJson($url, ['request_key' => 'same-diagnostic'])->assertAccepted()->assertJsonPath('data.status', 'queued')->json('data.id');
        $this->postJson($url, ['request_key' => 'same-diagnostic'])->assertAccepted()->assertJsonPath('data.id', $id);
        $run = IncidentDiagnostic::sole();
        app(IncidentDiagnostics::class)->execute($run->id);
        app(IncidentDiagnostics::class)->execute($run->id);
        Http::assertSentCount(1);
        $this->getJson('/api/v1/admin/incident-diagnostics/'.$id)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.advisory_only', true)
            ->assertJsonPath('data.human_review_required', true)->assertJsonPath('data.analysis.confidence', 'low');
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('new', $this->incident->fresh()->status);
        $this->assertSame(1, IncidentDiagnosticResult::count());
        $this->assertSame(1, DB::table('incident_diagnostic_quotas')->value('requests'));
        $this->assertTrue(app(AuditIntegrity::class)->verify()['valid']);
    }

    public function test_provider_receives_only_allowlisted_context_and_no_business_or_identity_data(): void
    {
        SecurityIncidentOccurrence::create(['incident_id' => $this->incident->id, 'request_id' => 'private-request-reference', 'actor_user_id' => $this->admin->id,
            'error_code' => 'private-secret-data', 'occurred_at' => now()]);
        Http::fake(function (ClientRequest $r) {
            $this->assertSame('https://api.openai.com/v1/responses', $r->url());
            $this->assertFalse($r['store']);
            $this->assertArrayNotHasKey('tools', $r->data());
            $this->assertTrue($r['text']['format']['strict']);
            $this->assertLessThanOrEqual(4000, $r['max_output_tokens']);
            foreach (['private-', $this->admin->public_id, $this->incident->public_id, 'sk-private-test-key'] as $private) {
                $this->assertStringNotContainsString($private, $r['input']);
            }

            return Http::response($this->response());
        });
        app(IncidentDiagnostics::class)->execute($this->requestDiagnostic()->id);
        Http::assertSentCount(1);
    }

    public function test_ineligible_incidents_and_disabled_configuration_cannot_consume_quota_or_call_provider(): void
    {
        Http::fake();
        $this->asAdmin();
        $url = '/api/v1/admin/incidents/'.$this->incident->public_id.'/diagnostics';
        config(['diagnostics.enabled' => false]);
        $this->postJson($url, ['request_key' => 'disabled-request'])->assertStatus(503)->assertJsonPath('error.code', 'diagnostics_not_configured');
        config(['diagnostics.enabled' => true]);
        $this->incident->forceFill(['http_status' => 403])->save();
        $this->postJson($url, ['request_key' => 'forbidden-request'])->assertConflict()->assertJsonPath('error.code', 'incident_not_diagnosable');
        $this->incident->forceFill(['http_status' => 500, 'status' => 'resolved'])->save();
        $this->postJson($url, ['request_key' => 'resolved-request'])->assertConflict();
        $this->assertSame(0, IncidentDiagnostic::count());
        Http::assertNothingSent();
    }

    public function test_daily_quota_and_request_key_conflict_are_enforced(): void
    {
        config(['diagnostics.daily_request_limit' => 1]);
        $this->asAdmin();
        $url = '/api/v1/admin/incidents/'.$this->incident->public_id.'/diagnostics';
        $this->postJson($url, ['request_key' => 'quota-request'])->assertAccepted();
        $this->postJson($url, ['request_key' => 'quota-request'])->assertAccepted();
        $this->postJson($url, ['request_key' => 'quota-another'])->assertStatus(429)->assertJsonPath('error.code', 'diagnostic_daily_limit');
        $other = $this->incident->replicate();
        $other->fingerprint = hash('sha256', 'other');
        $other->save();
        try {
            app(IncidentDiagnostics::class)->request($other, $this->admin, 'quota-request');
            $this->fail('Changed incident must conflict.');
        } catch (ApiException $e) {
            $this->assertSame('diagnostic_key_conflict', $e->errorCode);
        }
        $this->assertSame(1, IncidentDiagnostic::count());
    }

    public function test_malformed_refused_incomplete_or_tool_outputs_are_failed_without_retry_or_raw_output_storage(): void
    {
        $cases = [
            $this->response(array_replace($this->analysis(), ['evidence_refs' => ['invented']])),
            $this->response($this->analysis() + ['execute_sql' => 'DROP TABLE wallets']),
            ['status' => 'incomplete', 'output' => []],
            ['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'refund']]],
            ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'private refusal']]]]],
            ['status' => 'completed', 'output' => 'invalid'],
        ];
        foreach ($cases as $i => $response) {
            Http::fake();
            // Flush previous fake registrations: first matching callback wins.
            $this->app->forgetInstance(Factory::class);
            Http::swap(new Factory);
            Http::fake(['api.openai.com/*' => Http::response($response)]);
            $run = $this->requestDiagnostic('invalid-output-'.$i);
            app(IncidentDiagnostics::class)->execute($run->id);
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertSame('invalid_provider_output', $run->fresh()->failure_code);
            Http::assertSentCount(1);
        }
        $this->assertSame(0, IncidentDiagnosticResult::count());
    }

    public function test_failed_provider_does_not_retry_and_revoked_admin_is_not_sent_to_provider(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'private-provider-detail']], 429)]);
        $run = $this->requestDiagnostic();
        app(IncidentDiagnostics::class)->execute($run->id);
        app(IncidentDiagnostics::class)->execute($run->id);
        $this->assertSame('provider_rate_limited', $run->fresh()->failure_code);
        Http::assertSentCount(1);
        $second = $this->requestDiagnostic('revoked-request');
        $this->admin->platformRoles()->update(['revoked_at' => now()]);
        app(IncidentDiagnostics::class)->execute($second->id);
        $this->assertSame('no_longer_eligible', $second->fresh()->failure_code);
        Http::assertSentCount(1);
    }

    public function test_human_review_is_audited_once_and_never_closes_incident(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->response())]);
        $run = $this->requestDiagnostic();
        $this->asAdmin();
        $url = '/api/v1/admin/incident-diagnostics/'.$run->public_id.'/review';
        $this->postJson($url, ['decision' => 'accepted', 'note' => 'Investigation proposal reviewed'])->assertConflict();
        app(IncidentDiagnostics::class)->execute($run->id);
        $this->postJson($url, ['decision' => 'accepted', 'note' => 'Investigation proposal reviewed'])->assertOk()->assertJsonCount(1, 'data.reviews');
        $this->postJson($url, ['decision' => 'accepted', 'note' => 'Investigation proposal reviewed'])->assertConflict();
        $this->assertSame('new', $this->incident->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'admin.incident_diagnostic_reviewed']);
        $this->incident->increment('occurrences');
        $this->getJson('/api/v1/admin/incident-diagnostics/'.$run->public_id)->assertOk()->assertJsonPath('data.context_stale', true);
    }

    public function test_operational_budget_requires_published_reviewed_version_and_never_leaks_to_public_settings(): void
    {
        $settings = app(PlatformSettings::class);
        $version = $settings->draft($this->admin, 'operations.diagnostics_daily_request_limit', 0, 'Pause diagnostic requests for investigation');
        $this->assertSame(10, $settings->diagnosticDailyLimit());
        $settings->submit($version, $this->admin);
        $reviewer = User::factory()->create();
        $reviewer->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        $settings->publish($version, $reviewer, 'Independent budget review completed');
        $this->assertSame(0, $settings->diagnosticDailyLimit());
        $this->assertArrayNotHasKey('operations.diagnostics_daily_request_limit', $this->getJson('/api/v1/public/settings')->assertOk()->json('data.values'));
        $this->asAdmin();
        $this->postJson('/api/v1/admin/incidents/'.$this->incident->public_id.'/diagnostics', ['request_key' => 'paused-budget-request'])->assertStatus(429);
        $new = $settings->submit($settings->draft($this->admin, 'operations.diagnostics_daily_request_limit', 1000, 'Increase reviewed diagnostic limit'), $this->admin);
        $settings->publish($new, $reviewer, 'Independent budget review completed');
        $this->assertSame(10, $settings->diagnosticDailyLimit());
    }

    public function test_context_integrity_and_output_redaction_prevent_accidental_disclosure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->response(array_replace($this->analysis(), [
            'proposed_fix' => 'Contact private@example.test; sk-private-test-key <script>bad()</script>',
        ])))]);
        $run = $this->requestDiagnostic();
        app(IncidentDiagnostics::class)->execute($run->id);
        $result = IncidentDiagnosticResult::sole()->analysis;
        foreach (['private@example.test', 'sk-private-test-key', '<script>'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($result));
        }
        $tampered = $this->requestDiagnostic('tampered-context');
        DB::table('incident_diagnostics')->where('id', $tampered->id)->update(['context' => json_encode(['raw_payload' => 'private-secret'])]);
        app(IncidentDiagnostics::class)->execute($tampered->id);
        $this->assertSame('context_integrity_failed', $tampered->fresh()->failure_code);
        Http::assertSentCount(1);
        $this->incident->forceFill(['route' => 'private/customer@example.test', 'service' => 'private-secret'])->save();
        $safe = $this->requestDiagnostic('unknown-route-context')->context;
        $this->assertStringNotContainsString('private', json_encode($safe));
    }

    public function test_guards_run_before_lookup_and_reject_invalid_inputs(): void
    {
        $reads = ['/api/v1/admin/incidents/missing/diagnostics', '/api/v1/admin/incident-diagnostics/missing'];
        foreach ($reads as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        Sanctum::actingAs(User::factory()->create());
        foreach ($reads as $url) {
            $this->getJson($url)->assertForbidden();
        }
        foreach (['/api/v1/admin/incidents/missing/diagnostics', '/api/v1/admin/incident-diagnostics/missing/review'] as $url) {
            $this->asAdmin(password: false);
            $this->postJson($url, [])->assertStatus(423)->assertJsonPath('error.code', 'password_confirmation_required');
            $this->asAdmin(otp: false);
            $this->postJson($url, [])->assertStatus(423)->assertJsonPath('error.code', 'step_up_code_required');
        }
        $this->asAdmin();
        $this->postJson('/api/v1/admin/incidents/'.$this->incident->public_id.'/diagnostics', [])->assertUnprocessable();
        $this->getJson('/api/v1/admin/incident-diagnostics/missing')->assertNotFound();
    }
}
