<?php

namespace App\Services\Admin\Diagnostics;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Jobs\AnalyzeIncident;
use App\Models\IncidentDiagnostic;
use App\Models\IncidentDiagnosticResult;
use App\Models\IncidentDiagnosticReview;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class IncidentDiagnostics
{
    public function __construct(private readonly DiagnosticContext $context, private readonly OpenAiDiagnostics $provider, private readonly AuditLogger $audit) {}

    public function request(SecurityIncident $incident, User $actor, string $key): IncidentDiagnostic
    {
        return DB::transaction(function () use ($incident, $actor, $key) {
            $day = now()->utc()->toDateString();
            DB::table('incident_diagnostic_quotas')->upsert([['day' => $day, 'requests' => 0]], ['day'], ['day']);
            $quota = DB::table('incident_diagnostic_quotas')->where('day', $day)->lockForUpdate()->first();
            $existing = IncidentDiagnostic::where('requested_by', $actor->id)->where('request_key', $key)->first();
            if ($existing !== null) {
                if ($existing->incident_id !== $incident->id) {
                    throw ApiException::conflict('The diagnostic request key was already used for another incident.', 'diagnostic_key_conflict');
                }

                return $existing;
            }
            if (! $this->provider->configured()) {
                throw new ApiException('Incident diagnostics is not configured.', 503, 'diagnostics_not_configured');
            }
            $limit = app(PlatformSettings::class)->diagnosticDailyLimit();
            if ($quota->requests >= $limit) {
                throw new ApiException('The daily diagnostic request limit has been reached.', 429, 'diagnostic_daily_limit');
            }
            $incident = SecurityIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $context = $this->context->build($incident);
            $run = IncidentDiagnostic::create(['incident_id' => $incident->id, 'requested_by' => $actor->id, 'request_key' => $key,
                'status' => 'queued', 'context' => $context, 'context_hash' => $this->context->hash($context), 'model' => config('diagnostics.model')]);
            DB::table('incident_diagnostic_quotas')->where('day', $day)->increment('requests');
            $this->audit->record('admin.incident_diagnostic_requested', category: AuditCategory::Admin, resource: $run, actorUserId: $actor->id,
                metadata: ['incident' => $incident->public_id, 'context_hash' => $run->context_hash, 'model' => $run->model]);
            AnalyzeIncident::dispatch($run->id)->afterCommit();

            return $run;
        }, 3);
    }

    public function execute(int $id): void
    {
        if (IncidentDiagnostic::whereKey($id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        $run = IncidentDiagnostic::findOrFail($id);
        if (! hash_equals($run->context_hash, $this->context->hash($run->context))) {
            $this->fail($id, 'context_integrity_failed');

            return;
        }
        $actor = User::find($run->requested_by);
        $incident = SecurityIncident::findOrFail($run->incident_id);
        if ($actor === null || $actor->status->value !== 'active' || ! $actor->isSuperAdmin() || (config('shopbonus.admin.mfa_required') && ! $actor->hasEnabledTwoFactorAuthentication()) || in_array($incident->status, ['resolved', 'ignored'], true)) {
            $this->fail($id, 'no_longer_eligible');

            return;
        }
        try {
            $result = $this->provider->analyze($run->context, $run->model);
        } catch (ProviderFailure $e) {
            $this->fail($id, $e->failureCode);

            return;
        } catch (\Throwable) {
            $this->fail($id, 'invalid_provider_output');

            return;
        }
        DB::transaction(function () use ($run, $result) {
            $run = IncidentDiagnostic::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'running') {
                return;
            }
            IncidentDiagnosticResult::create(['diagnostic_id' => $run->id] + $result);
            $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();
            $this->audit->record('admin.incident_diagnostic_completed', category: AuditCategory::Admin, resource: $run, actorUserId: $run->requested_by,
                metadata: ['context_hash' => $run->context_hash]);
        });
    }

    public function fail(int $id, string $code): void
    {
        DB::transaction(function () use ($id, $code) {
            $run = IncidentDiagnostic::whereKey($id)->lockForUpdate()->first();
            if ($run === null || ! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }
            $run->forceFill(['status' => 'failed', 'failure_code' => $code, 'finished_at' => now()])->save();
            $this->audit->record('admin.incident_diagnostic_failed', category: AuditCategory::Admin, resource: $run, actorUserId: $run->requested_by, metadata: ['failure_code' => $code]);
        });
    }

    public function review(IncidentDiagnostic $run, User $actor, string $decision, string $note): IncidentDiagnosticReview
    {
        return DB::transaction(function () use ($run, $actor, $decision, $note) {
            $run = IncidentDiagnostic::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'completed') {
                throw ApiException::conflict('Only completed diagnostics can be reviewed.', 'diagnostic_not_completed');
            }
            if (IncidentDiagnosticReview::where('diagnostic_id', $run->id)->where('reviewed_by', $actor->id)->exists()) {
                throw ApiException::conflict('You have already reviewed this diagnostic result.', 'diagnostic_already_reviewed');
            }
            $review = IncidentDiagnosticReview::create(['diagnostic_id' => $run->id, 'reviewed_by' => $actor->id, 'decision' => $decision, 'note' => $note]);
            $this->audit->record('admin.incident_diagnostic_reviewed', category: AuditCategory::Admin, resource: $run, actorUserId: $actor->id,
                metadata: ['review' => $review->public_id, 'decision' => $decision]);

            return $review;
        });
    }
}
