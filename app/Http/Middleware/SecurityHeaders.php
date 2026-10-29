<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response hardening (Trello #22 security): no MIME sniffing, no
 * framing, no referrer leakage, and HSTS on HTTPS requests. Headers a
 * controller already set (e.g. stricter evidence downloads) are kept.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = ['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer'];
        $maxAge = (int) config('shopbonus.security.hsts_max_age');
        if ($request->isSecure() && $maxAge > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.$maxAge.'; includeSubDomains';
        }
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
