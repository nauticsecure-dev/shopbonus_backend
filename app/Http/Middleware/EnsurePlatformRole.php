<?php

namespace App\Http\Middleware;

use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `platform:super_admin`: the user must hold the (unrevoked) platform role.
 * With `shopbonus.admin.mfa_required`, confirmed 2FA is required as well.
 * Denials are 403 and therefore audited by AuditAccessDenials.
 */
class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasPlatformRole(PlatformRole::from($role))) {
            throw new ApiException('This action requires a platform administrator.', 403, 'platform_role_required');
        }

        // Super Admins must have confirmed TOTP two-factor authentication (B16a).
        if (config('shopbonus.admin.mfa_required') && ! $user->hasEnabledTwoFactorAuthentication()) {
            throw new ApiException('Enable two-factor authentication to use the admin area.', 403, 'mfa_required');
        }

        return $next($request);
    }
}
