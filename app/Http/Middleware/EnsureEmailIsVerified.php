<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces Laravel's `verified` alias (which expects signed-link
 * verification via MustVerifyEmail) with the OTP-based check.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->email_verified_at === null) {
            throw new ApiException('Verify your email address to continue.', 403, 'email_not_verified');
        }

        return $next($request);
    }
}
