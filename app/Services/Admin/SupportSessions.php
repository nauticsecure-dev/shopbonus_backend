<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Super Admin support sessions ("impersonation", Trello #21).
 *
 * Starting one issues a short-lived Sanctum token for the target user; the
 * admin's own session is untouched. Requests made with that token run as
 * the target, with the admin recorded as `real_actor_user_id` on every
 * audit event, and sensitive actions are refused (GuardSupportSession).
 * Ending or expiring the session deletes the token, so access stops on the
 * next request.
 */
class SupportSessions
{
    public const TOKEN_NAME = 'support-session';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{0: ImpersonationSession, 1: string} the session and the plain token (shown once)
     */
    public function start(User $admin, User $target, string $reason): array
    {
        if ($target->id === $admin->id) {
            throw new ApiException('You cannot start a support session for yourself.', 422, 'support_session_self');
        }

        if (! $target->isActive()) {
            throw new ApiException('Support sessions are only possible for active accounts.', 422, 'support_session_target_inactive');
        }

        if ($target->platformRoles()->whereNull('revoked_at')->exists()) {
            throw new ApiException('Support sessions cannot target platform administrators.', 422, 'support_session_target_admin');
        }

        $expiresAt = now()->addMinutes((int) config('shopbonus.admin.support_session_minutes'));

        [$session, $plain] = DB::transaction(function () use ($admin, $target, $reason, $expiresAt) {
            // One support session per admin at a time.
            ImpersonationSession::where('admin_user_id', $admin->id)->whereNull('ended_at')->lockForUpdate()->get()
                ->each(fn (ImpersonationSession $s) => $this->close($s, 'replaced'));

            $token = $target->createToken(self::TOKEN_NAME, ['*'], $expiresAt);

            $session = ImpersonationSession::create([
                'admin_user_id' => $admin->id,
                'target_user_id' => $target->id,
                'reason' => $reason,
                'token_id' => $token->accessToken->getKey(),
                'expires_at' => $expiresAt,
            ]);

            return [$session, $token->plainTextToken];
        });

        $this->audit->record(action: 'support_session.started', category: AuditCategory::Admin, resource: $session,
            metadata: ['target_user' => $target->public_id, 'reason' => $reason, 'expires_at' => $expiresAt->toIso8601String()],
            actorUserId: $admin->id);

        return [$session, $plain];
    }

    public function end(ImpersonationSession $session, string $reason): ImpersonationSession
    {
        $ended = DB::transaction(function () use ($session, $reason) {
            $locked = ImpersonationSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            return $locked->ended_at === null ? $this->close($locked, $reason) : null;
        });

        if ($ended === null) {
            return $session->refresh();
        }

        $this->audit->record(action: 'support_session.ended', category: AuditCategory::Admin, resource: $ended,
            metadata: ['target_user' => $ended->target->public_id, 'ended_reason' => $reason],
            actorUserId: $ended->admin_user_id);

        return $ended;
    }

    /**
     * The session a support token belongs to, or null if it is not one.
     * An expired session is closed here, on first use after expiry.
     */
    public function forToken(PersonalAccessToken $token): ?ImpersonationSession
    {
        if ($token->name !== self::TOKEN_NAME) {
            return null;
        }

        $session = ImpersonationSession::where('token_id', $token->getKey())->first();

        if ($session !== null && $session->ended_at === null && $session->expires_at->isPast()) {
            $this->end($session, 'expired');
        }

        return $session;
    }

    private function close(ImpersonationSession $session, string $reason): ImpersonationSession
    {
        if ($session->token_id !== null) {
            PersonalAccessToken::whereKey($session->token_id)->delete();
        }

        $session->forceFill(['ended_at' => now(), 'ended_reason' => $reason])->save();

        return $session;
    }
}
