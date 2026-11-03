<?php

namespace App\Http\Middleware;

use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Services\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `workspace.can:<permission>`: the resolved membership must hold the
 * permission. Members (who already know the workspace exists) get 403,
 * which AuditAccessDenials records with the webshop in context.
 *
 * Owner-only permissions additionally require confirmed Fortify TOTP when
 * `shopbonus.workspace.mfa_required_for_sensitive` is enabled (Trello #4).
 */
class RequireWorkspacePermission
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $required = WorkspacePermission::from($permission);

        if (! $this->current->allows($required)) {
            throw new ApiException(
                'You do not have permission to do this in this workspace.',
                403,
                'workspace_permission_denied',
                ['required_permission' => $required->value],
            );
        }

        if (
            $required->isOwnerOnly()
            && config('shopbonus.workspace.mfa_required_for_sensitive')
            && ! $request->user()?->hasEnabledTwoFactorAuthentication()
        ) {
            throw new ApiException(
                'Enable two-factor authentication to use this workspace action.',
                403,
                'mfa_required',
                ['required_permission' => $required->value],
            );
        }

        return $next($request);
    }
}
