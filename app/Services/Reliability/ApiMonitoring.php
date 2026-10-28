<?php

namespace App\Services\Reliability;

use App\Services\Admin\IncidentRecorder;
use Illuminate\Support\Facades\DB;

class ApiMonitoring
{
    public const SAMPLE_LIMIT = 50000;

    /** Bounded SQL window calculations; exact nearest-rank quantiles of disclosed observations.
     * @return array<string, mixed>
     */
    public function report(int $minutes = 5): array
    {
        $limit = max(100, min(self::SAMPLE_LIMIT, (int) config('reliability.sample_limit')));
        $until = now();
        $from = $until->subMinutes($minutes);
        $enabled = (bool) config('reliability.enabled');
        $base = DB::table('api_observations')->where('observed_at', '>=', $from)->where('observed_at', '<=', $until)
            ->orderByDesc('observed_at')->orderByDesc('id');
        $observed = DB::query()->fromSub((clone $base)->select('id')->limit($limit + 1), 'observed')->count();
        $ranked = DB::query()->fromSub((clone $base)->limit($limit), 'samples')->selectRaw('samples.*,
            ROW_NUMBER() OVER (PARTITION BY route, method ORDER BY duration_us, id ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) AS latency_rank,
            COUNT(*) OVER (PARTITION BY route, method ORDER BY duration_us, id ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) AS route_count');
        $groups = DB::query()->fromSub($ranked, 'ranked')->selectRaw('route, method, COUNT(*) AS requests,
            SUM(CASE WHEN status >= 400 AND status < 500 THEN 1 ELSE 0 END) AS client_errors,
            SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) AS server_errors,
            SUM(CASE WHEN status IN (408,504) THEN 1 ELSE 0 END) AS observed_timeouts,
            AVG(duration_us) AS average_us, SUM(query_count) AS queries, SUM(query_us) AS query_us, SUM(slow_queries) AS slow_queries,
            MIN(CASE WHEN latency_rank * 100 >= route_count * 50 THEN duration_us END) AS p50_us,
            MIN(CASE WHEN latency_rank * 100 >= route_count * 95 THEN duration_us END) AS p95_us,
            MIN(CASE WHEN latency_rank * 100 >= route_count * 99 THEN duration_us END) AS p99_us')
            ->groupBy('route', 'method')->orderBy('route')->orderBy('method')->limit(501)->get();
        $complete = $observed <= $limit && $groups->count() <= 500;
        $thresholds = $this->thresholds();
        $routes = $groups->take(500)->map(function (object $row) use ($enabled, $complete, $thresholds) {
            $count = (int) $row->requests;
            $errorRate = (int) $row->server_errors * 100 / $count;
            $p95 = (int) $row->p95_us / 1000;
            $sufficient = $enabled && $complete && $count >= $thresholds['minimum_samples'];
            $breaches = [];
            if ($sufficient && $errorRate > $thresholds['error_percent']) {
                $breaches[] = 'api_5xx_rate';
            }
            if ($sufficient && $p95 > $thresholds['p95_ms']) {
                $breaches[] = 'api_latency';
            }

            return ['route' => $row->route, 'method' => $row->method, 'requests' => $count,
                'state' => ! $sufficient ? 'UNKNOWN' : ($breaches === [] ? 'HEALTHY' : 'CRITICAL'), 'breaches' => $breaches,
                'average_ms' => round((float) $row->average_us / 1000, 3), 'p50_ms' => (int) $row->p50_us / 1000,
                'p95_ms' => $p95, 'p99_ms' => (int) $row->p99_us / 1000,
                'client_errors' => (int) $row->client_errors, 'server_errors' => (int) $row->server_errors,
                'client_error_percent' => round((int) $row->client_errors * 100 / $count, 4), 'server_error_percent' => round($errorRate, 4),
                'observed_timeouts' => (int) $row->observed_timeouts, 'observed_timeout_percent' => round((int) $row->observed_timeouts * 100 / $count, 4),
                'database' => ['queries' => (int) $row->queries, 'total_ms' => (int) $row->query_us / 1000, 'slow_queries' => (int) $row->slow_queries]];
        })->all();

        $state = ! $enabled || ! $complete || $routes === [] ? 'UNKNOWN'
            : (collect($routes)->contains('state', 'CRITICAL') ? 'CRITICAL' : (collect($routes)->contains('state', 'UNKNOWN') ? 'UNKNOWN' : 'HEALTHY'));

        return ['enabled' => $enabled, 'state' => $state,
            'from' => $from->toIso8601String(), 'to' => $until->toIso8601String(), 'sample_limit' => $limit,
            'sample_limit_exceeded' => $observed > $limit, 'routes_truncated' => $groups->count() > 500,
            'thresholds' => $thresholds, 'routes' => $routes,
            'coverage' => ['http' => 'observed_requests_only', 'timeouts' => 'rendered_408_504_only',
                'queue_wait' => 'UNKNOWN', 'provider_failures' => 'UNKNOWN', 'dependency_availability' => 'UNKNOWN', 'backups' => 'UNKNOWN']];
    }

    /** @return array{minimum_samples:int,error_percent:float,p95_ms:int} */
    public function thresholds(): array
    {
        return ['minimum_samples' => max(1, min(100000, (int) config('reliability.minimum_samples'))),
            'error_percent' => max(0.1, min(100, (float) config('reliability.error_percent'))),
            'p95_ms' => max(1, min(60000, (int) config('reliability.p95_ms')))];
    }

    public function evaluate(): int
    {
        if (! config('reliability.enabled')) {
            return 0;
        }
        $report = $this->report();
        $count = 0;
        foreach ($report['routes'] as $route) {
            foreach ($route['breaches'] as $breach) {
                app(IncidentRecorder::class)->monitoring($breach, $route['route'], $route['method'], [
                    'from' => $report['from'], 'to' => $report['to'], 'requests' => $route['requests'],
                    'server_errors' => $route['server_errors'], 'server_error_percent' => $route['server_error_percent'],
                    'p95_ms' => $route['p95_ms'], 'thresholds' => $report['thresholds'],
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function prune(): int
    {
        $cutoff = now()->subHours(max(1, min(72, (int) config('reliability.retention_hours'))));
        $deleted = 0;
        for ($batch = 0; $batch < 10; $batch++) {
            $ids = DB::table('api_observations')->where('observed_at', '<', $cutoff)->orderBy('observed_at')->limit(10000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table('api_observations')->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }
}
