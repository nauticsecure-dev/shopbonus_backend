<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * API language (Trello #5 "I18N"): the frontend sends the visitor's or
 * user's chosen language as `Accept-Language` (nl or en). Validation and auth
 * messages follow it; `Content-Language` says which one was used. Requests
 * without a supported preference use Dutch (NL default; EN fully available).
 */
class SetRequestLocale
{
    /** Ordered for future locales (e.g. de) without requiring them at launch. */
    public const SUPPORTED = ['nl', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->getPreferredLanguage(self::SUPPORTED);
        $explicit = $request->headers->has('Accept-Language') && $preferred !== null
            && str_contains(strtolower((string) $request->header('Accept-Language')), $preferred);

        // Always set it: a long-running worker must not carry one request's language into the next.
        // Default is NL when Accept-Language is missing or unsupported.
        App::setLocale($explicit ? $preferred : (string) config('app.locale', 'nl'));

        $response = $next($request);
        $response->headers->set('Content-Language', App::getLocale());

        return $response;
    }
}
