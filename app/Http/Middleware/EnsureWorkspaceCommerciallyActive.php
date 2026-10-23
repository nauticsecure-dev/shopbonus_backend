<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Agreements\AgreementService;
use App\Services\Workspaces\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `workspace.commercial`: for routes that create commercial effects (offers,
 * vouchers, integrations, payments). The resolved webshop must be approved
 * and its current merchant agreement accepted (Trello #16: "Only Approved
 * workspaces become commercially active"; re-acceptance rules).
 */
class EnsureWorkspaceCommerciallyActive
{
    public function __construct(
        private readonly CurrentWorkspace $current,
        private readonly AgreementService $agreements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $webshop = $this->current->webshop();

        if (! $webshop->status->isCommerciallyActive()) {
            throw new ApiException('This webshop is not active yet.', 403, 'workspace_not_active', ['status' => $webshop->status->value]);
        }

        if (! $this->agreements->hasAcceptedCurrent($webshop->loadMissing('businessProfile'))) {
            throw new ApiException('Accept the updated merchant agreement to continue.', 403, 'agreement_reacceptance_required');
        }

        return $next($request);
    }
}
