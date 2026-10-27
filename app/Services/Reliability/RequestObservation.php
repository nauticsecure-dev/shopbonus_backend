<?php

namespace App\Services\Reliability;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Request-scoped counters only: never retain SQL, bindings, headers, IDs or payloads. */
class RequestObservation
{
    private ?int $started = null;

    private int $queries = 0;

    private int $queryUs = 0;

    private int $slow = 0;

    private ?Request $request = null;

    public function start(Request $request): void
    {
        $this->started = null;
        $this->queries = $this->queryUs = $this->slow = 0;
        $this->request = $request;
        if (config('reliability.enabled') && ($request->is('api/*') || $request->is('login', 'register', 'logout', 'forgot-password', 'reset-password', 'user/*', 'two-factor-challenge', 'sanctum/csrf-cookie'))) {
            $this->started = hrtime(true);
        }
    }

    public function query(QueryExecuted $event): void
    {
        if ($this->started === null) {
            return;
        }
        $this->queries++;
        $this->queryUs += max(0, (int) round($event->time * 1000));
        if ($event->time >= max(1, (int) config('reliability.slow_query_ms'))) {
            $this->slow++;
        }
    }

    public function finish(Request $request, Response $response): void
    {
        if ($this->started === null || $this->request !== $request) {
            return;
        }
        $duration = max(0, intdiv(hrtime(true) - $this->started, 1000));
        $this->started = null; // Do not measure our own write or recurse on storage failure.
        $route = $request->route()?->uri() ?? 'unregistered';
        $method = in_array($request->method(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $request->method() : 'OTHER';
        try {
            DB::table('api_observations')->insert(['route' => mb_substr($route, 0, 190), 'method' => $method,
                'status' => $response->getStatusCode(), 'duration_us' => $duration,
                'query_count' => $this->queries, 'query_us' => $this->queryUs, 'slow_queries' => $this->slow, 'observed_at' => now()]);
        } catch (Throwable $e) {
            // Observability failure must not change a payment/auth response or recursively report SQL.
            Log::warning('API observation could not be stored', ['exception_class' => $e::class]);
        }
    }
}
