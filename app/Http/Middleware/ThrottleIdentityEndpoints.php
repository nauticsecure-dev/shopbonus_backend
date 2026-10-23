<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the identity rate limiters (FortifyServiceProvider) to Fortify's
 * own routes, which cannot take per-route middleware directly.
 *
 * Login is throttled by Fortify's route-level `throttle:login`, which does
 * not fire the Lockout event; this middleware fires it when login answers
 * 429 so lockouts reach the audit trail.
 */
class ThrottleIdentityEndpoints
{
    private const LIMITERS = [
        'register.store' => 'identity-register',
        'password.email' => 'identity-forgot-password',
        'password.update' => 'identity-reset-password',
        'user-password.update' => 'identity-change-password',
    ];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName() ?? '';

        if ($routeName === 'login.store') {
            try {
                $response = $next($request);
            } catch (ThrottleRequestsException $e) {
                event(new Lockout($request));

                throw $e;
            }

            if ($response->getStatusCode() === 429) {
                event(new Lockout($request));
            }

            return $response;
        }

        $limiter = self::LIMITERS[$routeName] ?? null;

        if ($limiter === null || $request->isMethodSafe()) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, $limiter);
    }
}
