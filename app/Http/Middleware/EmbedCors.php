<?php

namespace App\Http\Middleware;

use App\Models\BannerInstallation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public embed CORS is based on installed storefront origins, never SPA credentials. */
class EmbedCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = rtrim(strtolower((string) $request->header('Origin')), '/');
        if (! $request->is('api/v1/banners/*') || $origin === '') {
            return $next($request);
        }
        if (! BannerInstallation::query()->whereJsonContains('allowed_origins', $origin)->exists()) {
            $response = $next($request);
            $response->headers->remove('Access-Control-Allow-Origin');
            $response->headers->remove('Access-Control-Allow-Credentials');

            return $response;
        }

        // An actual request still checks that its specific key owns this origin.
        $response = $request->isMethod('OPTIONS') ? response('', 204) : $next($request);
        $response->headers->set('Access-Control-Allow-Origin', (string) $request->header('Origin'));
        $response->headers->remove('Access-Control-Allow-Credentials');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type, X-Banner-Editor-Token');
        $response->headers->set('Access-Control-Expose-Headers', 'X-Request-Id, Retry-After');
        $response->setVary('Origin', false);

        return $response;
    }
}
