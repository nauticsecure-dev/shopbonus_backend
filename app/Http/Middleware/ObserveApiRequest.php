<?php

namespace App\Http\Middleware;

use App\Services\Reliability\RequestObservation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ObserveApiRequest
{
    public function __construct(private readonly RequestObservation $observation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->observation->start($request);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->observation->finish($request, $response);
    }
}
