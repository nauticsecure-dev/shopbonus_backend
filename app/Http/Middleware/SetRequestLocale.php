<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * API language (Trello #5 "I18N"): the frontend sends the visitor's or
 * user's chosen language as `Accept-Language` (nl, en or de). Validation and auth
 * messages follow it; `Content-Language` says which one was used. Requests
 * without a supported preference use the configured default.
 */
class SetRequestLocale
{
    public const SUPPORTED = User::SUPPORTED_LOCALES;

    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->getPreferredLanguage(self::SUPPORTED);
        $explicit = $request->headers->has('Accept-Language') && $preferred !== null
            && str_contains(strtolower((string) $request->header('Accept-Language')), $preferred);

        // Always set it: a long-running worker must not carry one request's language into the next.
        // Default is NL when Accept-Language is missing or unsupported.
        $default = (string) config('app.locale', 'nl');
        $locale = $explicit ? $preferred : $default;
        App::setLocale($locale);

        try {
            $response = $next($request);
            $response->headers->set('Content-Language', $locale);

            return $response;
        } finally {
            // setLocale also writes app.locale: restore the configured default
            // so a worker's next request cannot inherit this visitor's choice.
            App::setLocale($default);
        }
    }
}
