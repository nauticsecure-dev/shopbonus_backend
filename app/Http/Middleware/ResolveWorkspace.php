<?php

namespace App\Http\Middleware;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves `{workspace}` (a webshop public ID, or `current` for the user's
 * server-side selection) to a webshop the user is an active member of.
 * Alias: `workspace`.
 *
 * - Not a member, removed/suspended membership, or no such webshop: the
 *   same generic 404, so other tenants' webshops are indistinguishable from
 *   missing ones. The attempt is audited.
 * - Member of a suspended webshop: 403 `workspace_suspended`.
 */
class ResolveWorkspace
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $reference = (string) $request->route('workspace');

        $webshop = $this->find($user, $reference);
        $membership = $webshop?->memberships()->where('user_id', $user->id)->first();

        if ($webshop === null || $membership === null || ! $membership->isActive()) {
            $this->audit->recordSafely(
                action: 'workspace.access_denied',
                outcome: AuditOutcome::Denied,
                category: AuditCategory::Workspace,
                metadata: ['workspace_ref' => Str::limit($reference, 64, ''), 'reason' => $this->reason($webshop, $membership)],
                httpStatus: 404,
                request: $request,
            );

            throw new NotFoundHttpException;
        }

        Context::add(AuditLogger::CONTEXT_WEBSHOP_ID, $webshop->id);
        $webshop->setRelation('memberships', collect([$membership]));
        $membership->setRelation('webshop', $webshop);

        if (! $webshop->status->allowsMemberAccess()) {
            throw new ApiException('This workspace is suspended.', 403, 'workspace_suspended');
        }

        $this->current->set($webshop, $membership);
        $request->route()?->forgetParameter('workspace');

        return $next($request);
    }

    private function find(User $user, string $reference): ?Webshop
    {
        if ($reference === 'current') {
            if ($user->current_webshop_id === null) {
                throw new ApiException('No workspace is selected.', 409, 'no_current_workspace');
            }

            return Webshop::find($user->current_webshop_id);
        }

        return Str::isUlid($reference) ? Webshop::where('public_id', Str::lower($reference))->first() : null;
    }

    private function reason(?Webshop $webshop, mixed $membership): string
    {
        return match (true) {
            $webshop === null => 'unknown_workspace',
            $membership === null => 'not_a_member',
            default => 'membership_'.$membership->status->value,
        };
    }
}
