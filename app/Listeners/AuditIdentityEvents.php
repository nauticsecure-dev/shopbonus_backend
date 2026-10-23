<?php

namespace App\Listeners;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Events\Registered;
use Laravel\Fortify\Fortify;

/**
 * Identity audit trail (Trello #4 "Security, audit"). Emails are never
 * stored; a keyed hash (`subject_ref`) links repeated failures for the same
 * address without revealing it. Passwords, codes and tokens are never read.
 */
class AuditIdentityEvents
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleRegistered(Registered $event): void
    {
        $this->record('identity.registered', AuditOutcome::Success, $event->user);
    }

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        $this->record('identity.login_succeeded', AuditOutcome::Success, $event->user);
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials[Fortify::username()] ?? null;

        $this->record('identity.login_failed', AuditOutcome::Failure, $event->user, [
            'subject_ref' => is_string($email) ? $this->subjectRef($email) : null,
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $event->request->input(Fortify::username());

        $this->record('identity.login_locked_out', AuditOutcome::Denied, null, [
            'subject_ref' => is_string($email) ? $this->subjectRef($email) : null,
        ], 429);
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('identity.logout', AuditOutcome::Success, $event->user);
    }

    public function handlePasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        $this->record('identity.password_reset_requested', AuditOutcome::Success, $event->user);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->record('identity.password_reset', AuditOutcome::Success, $event->user);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(string $action, AuditOutcome $outcome, mixed $user, array $metadata = [], ?int $status = null): void
    {
        $user = $user instanceof User ? $user : null;

        $this->audit->recordSafely(
            action: $action,
            outcome: $outcome,
            category: AuditCategory::Identity,
            resource: $user,
            metadata: array_filter($metadata),
            httpStatus: $status,
            actorUserId: $user?->id,
        );
    }

    private function subjectRef(string $email): string
    {
        return substr(hash_hmac('sha256', User::normalizeEmail($email), (string) config('app.key')), 0, 32);
    }
}
