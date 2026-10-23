<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\HealthChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    /**
     * Liveness: the PHP process is serving requests. No dependency checks.
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'shopbonus-api',
            'time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Readiness: dependencies needed to serve traffic are reachable.
     */
    public function ready(Request $request, HealthChecker $checker): JsonResponse
    {
        $results = $checker->run();
        $healthy = ! in_array(HealthChecker::FAIL, $results, true);

        $body = ['status' => $healthy ? 'ok' : 'fail'];

        if ($this->mayViewDetail($request)) {
            $body['checks'] = $results;
        }

        return response()->json($body, $healthy ? 200 : 503);
    }

    private function mayViewDetail(Request $request): bool
    {
        $token = config('shopbonus.health.readiness_token');
        $given = $request->headers->get('X-Health-Token');

        return is_string($token) && $token !== ''
            && is_string($given) && hash_equals($token, $given);
    }
}
