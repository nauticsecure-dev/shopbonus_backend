<?php

namespace App\Services\Admin;

use App\Models\ApplicationError;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentOccurrence;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Grouped notifications are the incident rows; each occurrence is retained separately. */
class IncidentRecorder
{
    public function http(Request $request, int $status, ?string $code): void
    {
        $route = $request->route()?->uri() ?? 'unregistered';
        $method = $request->method();
        $scope = $request->route('workspace');
        $this->store(hash('sha256', $method.'|'.$route.'|'.$status), [
            'category' => $status >= 500 ? 'application' : 'security', 'severity' => $status >= 500 ? 'error' : 'warning',
            'service' => str_contains($route, 'integrations') || str_contains($route, 'webhooks') ? 'integrations' : 'api',
            'route' => $route, 'http_method' => $method, 'http_status' => $status,
        ], $request->user()?->id, Context::get(AuditLogger::CONTEXT_WEBSHOP_ID),
            is_string($scope) && Str::isUlid($scope) ? Str::lower($scope) : null,
            // Only a stable technical error identifier, never message/headers/body/query.
            $code !== null && preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $code) ? $code : null);
    }

    public function technical(ApplicationError $error): void
    {
        $this->store(hash('sha256', $error->fingerprint), ['category' => $error->source->value, 'severity' => 'error',
            'service' => 'background_jobs', 'route' => null, 'http_method' => null, 'http_status' => null],
            null, $error->webshop_id, null, 'background_failure');
    }

    /**
     * Group threshold alerts in the existing incident workflow; ordinary401/403 do not alert.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function monitoring(string $code, string $route, string $method, array $evidence): void
    {
        $this->store(hash('sha256', 'monitoring|'.$code.'|'.$method.'|'.$route), [
            'category' => 'monitoring', 'severity' => 'error', 'service' => 'api_monitoring',
            'route' => $route, 'http_method' => $method, 'http_status' => null,
        ], null, null, null, $code, 300, $evidence);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $evidence
     */
    private function store(string $fingerprint, array $attributes, ?int $actor, ?int $webshop, ?string $scope, ?string $code, int $cooldown = 0, ?array $evidence = null): void
    {
        try {
            DB::transaction(function () use ($fingerprint, $attributes, $actor, $webshop, $scope, $code, $cooldown, $evidence) {
                // Unique fingerprint handles concurrent first occurrences; createOrFirst uses a savepoint.
                $incident = SecurityIncident::query()->createOrFirst(['fingerprint' => $fingerprint], $attributes + ['first_seen_at' => now(), 'last_seen_at' => now(), 'occurrences' => 0, 'status' => 'new']);
                $incident = SecurityIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
                if ($cooldown > 0 && $incident->occurrences > 0 && $incident->last_seen_at->greaterThan(now()->subSeconds($cooldown))) {
                    return;
                }
                if ($cooldown > 0 && in_array($incident->status, ['resolved', 'ignored'], true)) {
                    $incident->status = 'new';
                }
                $incident->forceFill(['occurrences' => $incident->occurrences + 1, 'last_seen_at' => now()])->save();
                SecurityIncidentOccurrence::create(['incident_id' => $incident->id, 'request_id' => Context::get('request_id'),
                    'actor_user_id' => $actor, 'webshop_id' => $webshop, 'scope_public_id' => $scope, 'error_code' => $code, 'monitoring_evidence' => $evidence, 'occurred_at' => now()]);
            }, 3);
        } catch (Throwable $e) {
            // Do not recurse through report()/ErrorRecorder or turn denials into 500s.
            Log::error('Incident storage failed', ['exception_class' => $e::class]);
        }
    }
}
