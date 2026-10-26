<?php

namespace App\Http\Middleware;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Models\ImpersonationSession;
use App\Services\Admin\SupportSessions;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies support-session rules to requests made with a support token
 * (Trello #21 "Impersonation"):
 *
 * - a request with both a session cookie and a bearer token is refused
 *   (400 `ambiguous_credentials`), so the admin UI never acts as itself by mistake;
 * - an ended or expired session is refused (401) and its token deleted;
 * - the real admin is put in Context, so every audit event of the request
 *   carries `real_actor_user_id`;
 * - financial, security, ownership, team, agreement and data-export
 *   actions are refused (403 `blocked_during_impersonation`);
 * - every request is recorded as `support_session.request`.
 *
 * Fortify routes (/user/password, two-factor, confirm-password) use the
 * session guard and never accept the token, and `password.recent` routes
 * need a session, so those are unreachable too.
 * Alias: `support.session`.
 */
class GuardSupportSession
{
    /** Refused for every method. */
    private const BLOCKED_ALWAYS = [
        'api.v1.admin.*',
        'api.v1.workspaces.banners.ai.*',
        'api.v1.workspaces.banners.experiment.*',
        'api.v1.consumer.vouchers.show',   // reveals a voucher code
        'api.v1.consumer.privacy.*',       // data export, deletion
        'api.v1.workspaces.codes.*',
    ];

    /** Refused for anything but GET/HEAD. */
    private const BLOCKED_WRITES = [
        'api.v1.auth.*',
        'api.v1.claims.accept',
        'api.v1.invitations.accept',
        'api.v1.consumer.checkouts.*',
        'api.v1.consumer.email-change.*',
        'api.v1.consumer.disputes.store',
        'api.v1.workspaces.store',
        'api.v1.workspaces.agreement.*',
        'api.v1.workspaces.code-imports.*',
        'api.v1.workspaces.connections.*',
        'api.v1.workspaces.funding.*',
        'api.v1.workspaces.invitations.*',
        'api.v1.workspaces.members.*',
        'api.v1.workspaces.ownership.*',
        'api.v1.workspaces.vouchers.redeem',
        'api.v1.workspaces.disputes.*',
    ];

    public function __construct(
        private readonly SupportSessions $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Sanctum prefers the cookie session over a bearer token; a client sending both would
        // silently act as the session user. Refuse instead of guessing.
        if ($token instanceof TransientToken && $request->bearerToken() !== null) {
            throw new ApiException('Send either the session cookie or a bearer token, not both.', 400, 'ambiguous_credentials');
        }
        $session = $token instanceof PersonalAccessToken ? $this->sessions->forToken($token) : null;

        if ($token instanceof PersonalAccessToken && $token->name === SupportSessions::TOKEN_NAME && ($session === null || ! $session->isActive())) {
            $token->delete();

            throw new ApiException('This support session has ended.', 401, 'support_session_ended');
        }

        if ($session === null) {
            return $next($request);
        }

        Context::add(AuditLogger::CONTEXT_REAL_ACTOR_ID, $session->admin_user_id);
        $request->attributes->set('support_session', $session);

        try {
            if ($this->blocked($request)) {
                $this->record($request, $session, 403, AuditOutcome::Denied);

                throw new ApiException('This action is not available during a support session.', 403, 'blocked_during_impersonation');
            }

            $response = $next($request);
            $this->record($request, $session, $response->getStatusCode(),
                $response->getStatusCode() < 400 ? AuditOutcome::Success : AuditOutcome::Failure);

            return $response;
        } finally {
            Context::forget(AuditLogger::CONTEXT_REAL_ACTOR_ID);
        }
    }

    private function blocked(Request $request): bool
    {
        $name = (string) $request->route()?->getName();

        return Str::is(self::BLOCKED_ALWAYS, $name)
            || (! $request->isMethodSafe() && Str::is(self::BLOCKED_WRITES, $name));
    }

    private function record(Request $request, ImpersonationSession $session, int $status, AuditOutcome $outcome): void
    {
        $this->audit->recordSafely(
            action: 'support_session.request',
            outcome: $outcome,
            category: AuditCategory::Admin,
            resource: $session,
            metadata: ['route_name' => $request->route()?->getName()],
            httpStatus: $status,
        );
    }
}
