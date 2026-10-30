<?php

namespace App\Services\Qa;

use App\Models\QaTestResult;
use App\Models\QaTestRun;
use App\Services\Admin\IncidentRecorder;
use App\Services\Health\HealthChecker;
use Illuminate\Support\Str;

/**
 * Read-only "safe suite" (Trello #37 §7, every 5 minutes on production):
 * database, cache and Redis connectivity plus latency, through the existing
 * HealthChecker. No account creation, purchases, financial writes or other
 * business requests. A failure or slow probe raises a grouped incident.
 */
class QaProbe
{
    public function __construct(private readonly HealthChecker $health, private readonly IncidentRecorder $incidents) {}

    public function run(): QaTestRun
    {
        $started = hrtime(true);
        $checks = $this->health->run();
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);
        $slow = $ms > (int) config('qa.probe_slow_ms');
        $results = collect($checks)->map(fn ($s, $name) => ['key' => 'probe::'.$name, 'status' => $s === HealthChecker::OK ? 'pass' : 'fail', 'message' => $s === HealthChecker::OK ? null : 'Health check failed.'])
            ->values()->push(['key' => 'probe::latency', 'status' => $slow ? 'fail' : 'pass', 'message' => $slow ? "Probe took {$ms} ms (limit ".config('qa.probe_slow_ms').' ms).' : null]);
        $fail = $results->where('status', 'fail')->count();
        $run = QaTestRun::create(['suite' => 'safe', 'environment' => app()->environment('production') ? 'production' : 'staging',
            'git_sha' => (string) config('qa.release_sha'), 'source' => 'probe', 'report_hash' => hash('sha256', 'probe|'.Str::uuid()),
            'totals' => ['total' => $results->count(), 'assertions' => $results->count(), 'pass' => $results->count() - $fail, 'fail' => $fail,
                'blocked' => 0, 'not_implemented' => 0, 'skipped' => 0, 'mocked' => 0],
            'duration_ms' => $ms, 'started_at' => now(), 'finished_at' => now()]);
        QaTestResult::insert($results->map(fn ($r) => ['public_id' => strtolower((string) Str::ulid()), 'run_id' => $run->id, 'test_case_id' => null,
            'key' => $r['key'], 'status' => $r['status'], 'assertions' => 1, 'mocked' => false, 'duration_ms' => $ms, 'message' => $r['message'], 'created_at' => now()])->all());
        if ($fail > 0) {
            $this->incidents->monitoring('qa_probe_failed', 'qa:probe', 'GET', ['run' => $run->public_id, 'failed' => $results->where('status', 'fail')->pluck('key')->all(), 'duration_ms' => $ms]);
        }

        return $run;
    }
}
