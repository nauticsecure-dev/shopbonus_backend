<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces the `guest` alias. Guest-only routes (/login, /register,
 * /forgot-password, /reset-password) answer a signed-in JSON client with a
 * 409 envelope instead of Laravel's redirect to a home page.
 */
class RejectAuthenticatedJsonRequests extends RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        if ($request->expectsJson()) {
            foreach ($guards === [] ? [null] : $guards as $guard) {
                if ($request->user($guard) !== null || auth()->guard($guard)->check()) {
                    throw ApiException::conflict('You are already signed in.', 'already_authenticated');
                }
            }
        }

        return parent::handle($request, $next, ...$guards);
    }
}
