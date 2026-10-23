<?php

namespace App\Http\Middleware;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every 401/403 API response in the security audit trail, including
 * cross-workspace access attempts once policies return 403/404 (B03).
 *
 * Runs outside route middleware, so it sees responses rendered from auth and
 * policy exceptions. throttle:api runs inside it, so floods are capped by
 * the rate limiter before they reach the audit table.
 */
class AuditAccessDenials
{
    private const ACTIONS = [
        401 => 'auth.unauthenticated',
        403 => 'auth.forbidden',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();

        if (isset(self::ACTIONS[$status])) {
            $this->audit->recordSafely(
                action: self::ACTIONS[$status],
                outcome: AuditOutcome::Denied,
                category: AuditCategory::Security,
                metadata: array_filter(['error_code' => $this->errorCode($response)]),
                httpStatus: $status,
                request: $request,
            );
        }

        return $response;
    }

    private function errorCode(Response $response): ?string
    {
        if (! $response instanceof JsonResponse) {
            return null;
        }

        $code = $response->getData(true)['error']['code'] ?? null;

        return is_string($code) ? $code : null;
    }
}
