<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up for sensitive actions: the session must have confirmed the
 * password (POST /user/confirm-password) within `auth.password_timeout`.
 * Bearer-token requests have no session and are always refused, so these
 * actions are only possible from a signed-in browser session.
 * Alias: `password.recent`.
 */
class RequireRecentPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->hasSession() ? (int) $request->session()->get('auth.password_confirmed_at', 0) : 0;

        if (time() - $confirmedAt > (int) config('auth.password_timeout', 10800)) {
            throw new ApiException('Confirm your password to continue.', 423, 'password_confirmation_required');
        }

        return $next($request);
    }
}
