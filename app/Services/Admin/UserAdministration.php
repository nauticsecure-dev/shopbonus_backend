<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\ImpersonationSession;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin account actions (Trello #21 "Users, memberships & access").
 *
 * Passwords are never set or shown here. Suspension and a security reset
 * end the user's API tokens, browser sessions (database session driver)
 * and any support session on them at once. The last active Super Admin can
 * be neither suspended nor revoked.
 */
class UserAdministration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupportSessions $supportSessions,
    ) {}

    public function suspend(User $admin, User $user, string $reason): User
    {
        if ($user->is($admin)) {
            throw ApiException::conflict('You cannot suspend your own account.', 'cannot_manage_self');
        }

        if ($user->status !== UserStatus::Active) {
            throw ApiException::conflict('Only active accounts can be suspended.', 'invalid_status_transition');
        }

        DB::transaction(function () use ($user, $reason) {
            if ($user->isSuperAdmin()) {
                $this->assertNotLastAdmin($user);
            }

            $user->forceFill(['status' => UserStatus::Suspended])->save();
            $this->endAccess($user);
            $this->audit->record('admin.user_suspended', category: AuditCategory::Admin, resource: $user, metadata: ['reason' => $reason]);
        });

        return $user->refresh();
    }

    public function reactivate(User $admin, User $user, string $reason): User
    {
        if ($user->status !== UserStatus::Suspended) {
            throw ApiException::conflict('Only suspended accounts can be reactivated.', 'invalid_status_transition');
        }

        $user->forceFill(['status' => UserStatus::Active])->save();
        $this->audit->record('admin.user_reactivated', category: AuditCategory::Admin, resource: $user, metadata: ['reason' => $reason]);

        return $user->refresh();
    }

    /**
     * "Reset security access": turns off 2FA and signs the user out
     * everywhere, so they can enrol again after signing in with their
     * password (or resetting it themselves).
     */
    public function resetSecurity(User $admin, User $user, string $reason): User
    {
        if ($user->is($admin)) {
            throw ApiException::conflict('You cannot reset your own security access.', 'cannot_manage_self');
        }

        DB::transaction(function () use ($user, $reason) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
            $this->endAccess($user);
            $this->audit->record('admin.user_security_reset', category: AuditCategory::Admin, resource: $user, metadata: ['reason' => $reason]);
        });

        return $user->refresh();
    }

    /**
     * Revokes the Super Admin role. Granting stays console-only
     * (`shopbonus:grant-super-admin`): provisioning is internal.
     */
    public function revokeSuperAdmin(User $admin, User $user, string $reason): User
    {
        DB::transaction(function () use ($admin, $user, $reason) {
            $role = PlatformRoleAssignment::where('user_id', $user->id)->where('role', PlatformRole::SuperAdmin)
                ->whereNull('revoked_at')->lockForUpdate()->first();

            if ($role === null) {
                throw ApiException::conflict('This user is not a Super Admin.', 'not_super_admin');
            }

            $this->assertNotLastAdmin($user);
            $role->forceFill(['revoked_at' => now()])->save();

            ImpersonationSession::where('admin_user_id', $user->id)->whereNull('ended_at')->get()
                ->each(fn (ImpersonationSession $s) => $this->supportSessions->end($s, 'ended_by_admin'));

            $this->audit->record('admin.super_admin_revoked', category: AuditCategory::Admin, resource: $user,
                metadata: ['via' => 'api', 'reason' => $reason, 'by' => $admin->public_id]);
        });

        return $user->refresh();
    }

    private function assertNotLastAdmin(User $user): void
    {
        $others = PlatformRoleAssignment::where('role', PlatformRole::SuperAdmin)->whereNull('revoked_at')
            ->where('user_id', '!=', $user->id)
            ->whereHas('user', fn ($q) => $q->where('status', UserStatus::Active))
            ->lockForUpdate()->count();

        if ($others === 0) {
            throw ApiException::conflict('The last active Super Admin cannot be removed or suspended.', 'last_super_admin');
        }
    }

    private function endAccess(User $user): void
    {
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        ImpersonationSession::where('target_user_id', $user->id)->whereNull('ended_at')->get()
            ->each(fn (ImpersonationSession $s) => $this->supportSessions->end($s, 'ended_by_admin'));
    }
}
