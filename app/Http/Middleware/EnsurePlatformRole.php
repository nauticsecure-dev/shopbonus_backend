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

        return $next($request);
    }
}
