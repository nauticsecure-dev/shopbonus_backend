<?php

namespace App\Http\Middleware;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiExceptionRenderer;
use App\Services\Admin\IncidentRecorder;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordSecurityIncidents
{
    public function __construct(private readonly IncidentRecorder $incidents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (ApiExceptionRenderer::appliesTo($request) && in_array($response->getStatusCode(), [401, 403, 419, 422, 429, 500], true)) {
            $code = $response instanceof JsonResponse ? ($response->getData(true)['error']['code'] ?? null) : null;
            $this->incidents->http($request, $response->getStatusCode(), is_string($code) ? $code : null);
            if ($response->getStatusCode() === 403 && ! in_array('api', $request->route()?->gatherMiddleware() ?? [], true)) {
                app(AuditLogger::class)->recordSafely('auth.forbidden',
                    category: AuditCategory::Security, outcome: AuditOutcome::Denied,
                    httpStatus: 403, request: $request);
            }

        }

        return $response;
    }
}
