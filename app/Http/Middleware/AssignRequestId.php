<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID, shares it with logs/queued jobs via
 * Context, and echoes it back in the response header.
 *
 * A client-supplied X-Request-Id is reused only when it is a short, safe token,
 * so callers can correlate across systems without being able to inject
 * arbitrary content into our logs.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    private const SAFE_PATTERN = '/^[A-Za-z0-9._-]{8,128}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = self::resolve($request);

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Context::add(self::ATTRIBUTE, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * Returns the request's correlation ID, assigning one if the middleware
     * has not run yet (e.g. an exception thrown before the pipeline).
     */
    public static function resolve(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $incoming = $request->headers->get(self::HEADER);

        $requestId = is_string($incoming) && preg_match(self::SAFE_PATTERN, $incoming)
            ? $incoming
            : (string) Str::uuid7();

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }
}
