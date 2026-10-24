<?php

namespace App\Console\Commands;

use App\Enums\AuditCategory;
use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Private provisioning of Super Admins (Trello #4: "internally provisioned;
 * never available through public registration"). There is no API for granting;
 * revoking is also possible via POST /admin/users/{user}/revoke-super-admin.
 */
class GrantSuperAdmin extends Command
{
    protected $signature = 'shopbonus:grant-super-admin {email} {--revoke : Revoke instead of grant}';

    protected $description = 'Grant or revoke the Super Admin platform role for an existing, verified, active user';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('email', User::normalizeEmail((string) $this->argument('email')))->first();

        if ($user === null || $user->email_verified_at === null || ! $user->isActive()) {
            $this->error('No verified, active user with that email.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $others = $user->platformRoles()->getModel()->newQuery()->where('role', PlatformRole::SuperAdmin)->whereNull('revoked_at')
                ->where('user_id', '!=', $user->id)->whereHas('user', fn ($q) => $q->where('status', 'active'))->count();
            if ($user->isSuperAdmin() && $others === 0) {
                $this->error('This is the last active Super Admin; grant another one first.');

                return self::FAILURE;
            }

            $revoked = $user->platformRoles()->where('role', PlatformRole::SuperAdmin)->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            $audit->record('admin.super_admin_revoked', category: AuditCategory::Admin, resource: $user, metadata: ['via' => 'console', 'rows' => $revoked]);
            $this->info($revoked > 0 ? 'Super Admin role revoked.' : 'User was not a Super Admin.');

            return self::SUCCESS;
        }

        if ($user->isSuperAdmin()) {
            $this->info('User is already a Super Admin.');

            return self::SUCCESS;
        }

        $user->platformRoles()->create([
            'role' => PlatformRole::SuperAdmin,
            'granted_via' => 'console',
            'granted_at' => now(),
        ]);
        $audit->record('admin.super_admin_granted', category: AuditCategory::Admin, resource: $user, metadata: ['via' => 'console']);

        $this->info('Super Admin role granted.');
        if (config('shopbonus.admin.mfa_required')) {
            $this->warn('The admin API stays closed (403 mfa_required) until this user enables two-factor authentication.');
        }

        return self::SUCCESS;
    }
}
