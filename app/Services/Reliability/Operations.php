<?php

namespace App\Services\Reliability;

use App\Models\AffiliateConnection;
use App\Models\FinancialReconciliationRun;
use App\Models\SecurityIncident;
use App\Services\Admin\IncidentRecorder;
use App\Services\Health\HealthChecker;
use App\Services\Reliability\Recovery\Backups;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

class Operations
{
    public function jobStarted(JobProcessing $event): void
    {
        if ($event->connectionName !== config('queue.default') || ! in_array($event->job->getQueue(), self::queues(), true)
            || config('queue.connections.'.$event->connectionName.'.driver') === 'sync') {
            return;
        }
        try {
            $created = $event->job->payload()['createdAt'] ?? null;
            DB::table('operational_observations')->insert(['component' => 'queue_started', 'observed_at' => now(), 'metrics' => json_encode([
                'queue' => $event->job->getQueue(),
                'enqueue_age_ms' => is_numeric($created) ? min(604800000, max(0, (int) ((microtime(true) - (float) $created) * 1000))) : null,
            ], JSON_THROW_ON_ERROR)]);
        } catch (Throwable $e) {
            Log::warning('Queue observation failed', ['exception_class' => $e::class]);
        }
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $health = app(HealthChecker::class)->run();
        $components = [];
        foreach ($health as $key => $value) {
            $components[$key] = ['status' => $value === HealthChecker::OK ? 'HEALTHY' : 'CRITICAL', 'observed_at' => now()->toIso8601String()];
        }
        $components['api'] = $this->safe(fn () => app(ApiMonitoring::class)->report());
        $components['queue'] = $this->safe(fn () => $this->queue());
        $components['providers'] = $this->safe(function () {
            $http = app(ProviderObservations::class)->report();
            $errors = AffiliateConnection::whereNotNull('last_error')->count();

            return ['status' => $http['http_status'] === 'CRITICAL' ? 'CRITICAL' : ($errors > 0 || $http['http_status'] === 'WARNING' ? 'WARNING' : 'UNKNOWN'),
                'failed_affiliate_connections' => $errors, 'http_observations' => $http,
                'definition' => 'Persisted affiliate errors plus opt-in HTTP observations; no independent provider availability proof.'];
        });
        $components['financial'] = $this->safe(function () {
            $run = FinancialReconciliationRun::latest('id')->first();

            return $run === null ? ['status' => 'UNKNOWN'] : app(FinancialReconciliation::class)->summary($run);
        });
        $components['scheduler'] = $this->safe(function () {
            $last = DB::table('operational_observations')->where('component', 'scheduler')->latest('id')->first();

            return ['status' => $last === null ? 'UNKNOWN' : (strtotime($last->observed_at) < now()->subMinutes(5)->getTimestamp() ? 'CRITICAL' : 'HEALTHY'),
                'last_observed_at' => $last?->observed_at];
        });
        $components['incidents'] = $this->safe(fn () => ['status' => SecurityIncident::whereNotIn('status', ['resolved', 'ignored'])->exists() ? 'WARNING' : 'UNKNOWN',
            'open' => SecurityIncident::whereNotIn('status', ['resolved', 'ignored'])->count()]);
        $components['security'] = $this->safe(function () {
            $open = SecurityIncident::whereNotIn('status', ['resolved', 'ignored']);
            $critical = (clone $open)->where('severity', 'critical')->count();
            $high = (clone $open)->where('severity', 'high')->count();
            $total = (clone $open)->count();

            return [
                'status' => $critical > 0 ? 'CRITICAL' : ($high > 0 ? 'WARNING' : ($total > 0 ? 'WARNING' : 'UNKNOWN')),
                'open_critical' => $critical,
                'open_high' => $high,
                'open_total' => $total,
                'definition' => 'Open security/monitoring incidents. Absence of open rows is UNKNOWN, not HEALTHY — silent collectors are not proof of safety.',
            ];
        });
        $components['backups'] = $this->safe(fn () => app(Backups::class)->status());
        $components['acceptance_metrics'] = $this->safe(fn () => $this->acceptanceMetrics());

        return ['observed_at' => now()->toIso8601String(), 'components' => $components,
            'links' => [
                'monitoring' => '/api/v1/admin/monitoring',
                'incidents' => '/api/v1/admin/incidents',
                'financial' => '/api/v1/admin/reconciliation',
                'audit' => '/api/v1/admin/activity',
                'cockpit' => '/api/v1/admin/cockpit',
            ],
            'limits' => 'Local observations only. Missing data is UNKNOWN; a successful probe is not uptime, offsite durability or provider settlement proof.'];
    }

    /** Compact acceptance counters for Super Admin ops; never invent HEALTHY from missing samples. */
    private function acceptanceMetrics(): array
    {
        $run = FinancialReconciliationRun::latest('id')->first();
        $api = app(ApiMonitoring::class)->report();
        $backups = app(Backups::class)->status();
        $failedJobs = (int) DB::table('failed_jobs')->count();
        $openCritical = SecurityIncident::whereNotIn('status', ['resolved', 'ignored'])->where('severity', 'critical')->count();
        $apiState = $api['state'] ?? 'UNKNOWN';

        return [
            'status' => ($run !== null && $run->discrepancies > 0) || $openCritical > 0
                || (($backups['status'] ?? null) === 'CRITICAL') || $apiState === 'CRITICAL' ? 'CRITICAL'
                : (($failedJobs > 0 || $apiState === 'WARNING') ? 'WARNING' : 'UNKNOWN'),
            'ledger_discrepancy_count' => $run?->discrepancies,
            'reconciliation_run' => $run?->public_id,
            'reconciliation_elapsed_including_scheduling_ms' => $run === null ? null
                : max(0, (int) $run->created_at->diffInMilliseconds($run->completed_at ?? now())),
            'critical_api_status' => $apiState,
            'failed_queue_jobs' => $failedJobs,
            'open_critical_security_findings' => $openCritical,
            'backup_status' => $backups['status'] ?? 'UNKNOWN',
            'backup_success_pct_30d' => $backups['backup_success_percent_30d'] ?? null,
            'last_verified_restore_at' => $backups['last_verified_restore_at'] ?? null,
            'definition' => 'Observed counters only. Duplicate financial detection is per-source in reconciliation evidence; P95 lives under api monitoring.',
        ];
    }

    public function observe(): void
    {
        DB::table('operational_observations')->insert(['component' => 'scheduler', 'observed_at' => now(), 'metrics' => '{}']);
        $report = $this->report();
        foreach ($report['components'] as $name => $component) {
            if (($component['status'] ?? $component['state'] ?? null) === 'CRITICAL') {
                app(IncidentRecorder::class)->monitoring('operations_'.$name, 'operations', 'SYSTEM', ['component' => $name, 'status' => 'CRITICAL', 'observation' => $component]);
            }
        }
        $ids = DB::table('operational_observations')->where('observed_at', '<', now()->subHours(24))->orderBy('id')->limit(10000)->pluck('id');
        DB::table('operational_observations')->whereIn('id', $ids)->delete();
    }

    /** @return array<string,mixed> */
    /** @return list<string> */
    private static function queues(): array
    {
        $queues = array_values(array_unique(array_map('trim', (array) config('reliability.monitored_queues', ['default']))));

        return in_array('default', $queues, true) ? $queues : ['default', ...$queues];
    }

    /**
     * Worst status across the monitored queues. Top-level fields describe
     * `default` (unchanged contract); `queues` has one entry per queue.
     */
    private function queue(): array
    {
        $connection = config('queue.default');
        $driver = config('queue.connections.'.$connection.'.driver');
        if (! in_array($driver, ['database', 'redis'], true)) {
            return ['status' => 'UNKNOWN', 'reason' => 'No observable database/redis background queue configured.'];
        }
        $threshold = max(60, min(3600, (int) config('reliability.queue_stall_seconds', 300)));
        $observations = DB::table('operational_observations')->where('component', 'queue_started')->latest('id')->limit(500)->get();
        $queues = [];
        foreach (self::queues() as $name) {
            $size = Queue::connection()->size($name);
            // Observations recorded before B31 carry no queue name; they came from `default`.
            $last = $observations->first(fn ($o) => (json_decode($o->metrics, true)['queue'] ?? 'default') === $name);
            $readyAge = null;
            if ($driver === 'database') {
                $table = config('queue.connections.'.$connection.'.table', 'jobs');
                $oldest = DB::connection(config('queue.connections.'.$connection.'.connection'))->table($table)->where('queue', $name)
                    ->whereNull('reserved_at')->where('available_at', '<=', now()->getTimestamp())->min('available_at');
                $readyAge = $oldest === null ? null : max(0, now()->getTimestamp() - (int) $oldest);
            }
            $recent = $last !== null && strtotime($last->observed_at) >= now()->subSeconds($threshold)->getTimestamp();
            $queues[$name] = ['status' => $readyAge !== null && $readyAge > $threshold ? 'CRITICAL' : ($size > 0 && ! $recent ? 'WARNING' : 'UNKNOWN'),
                'size' => $size, 'ready_wait_seconds' => $readyAge, 'last_started_at' => $last?->observed_at,
                'last_enqueue_age_ms' => $last === null ? null : (json_decode($last->metrics, true)['enqueue_age_ms'] ?? null)];
        }
        $statuses = array_column($queues, 'status');
        $default = $queues['default'];

        return ['status' => in_array('CRITICAL', $statuses, true) ? 'CRITICAL' : (in_array('WARNING', $statuses, true) ? 'WARNING' : 'UNKNOWN'),
            'queue' => 'default', 'size' => $default['size'], 'ready_wait_seconds' => $default['ready_wait_seconds'], 'last_started_at' => $default['last_started_at'],
            'last_enqueue_age_ms' => $default['last_enqueue_age_ms'], 'queues' => $queues,
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'definition' => 'Database ready wait excludes delayed jobs. Enqueue age includes delays; Redis ready wait/worker availability stay unknown.'];
    }

    /** @return array<string,mixed> */
    private function safe(callable $read): array
    {
        try {
            return $read();
        } catch (Throwable) {
            return ['status' => 'CRITICAL', 'reason' => 'observation_failed'];
        }
    }
}
