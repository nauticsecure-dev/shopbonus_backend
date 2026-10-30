<?php

namespace App\Console\Commands;

use App\Enums\AuditCategory;
use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Private provisioning of Quality Center roles (Trello #37): `qa_tester` runs,
 * approves and retests; `developer` sees assigned findings and records fixes.
 * Neither role reaches any other admin API.
 */
class GrantQaRole extends Command
{
    protected $signature = 'shopbonus:qa-role {email} {role : qa_tester or developer} {--revoke : Revoke instead of grant}';

    protected $description = 'Grant or revoke a Quality Center platform role for an existing, verified, active user';

    public function handle(AuditLogger $audit): int
    {
        $role = PlatformRole::tryFrom((string) $this->argument('role'));
        if (! in_array($role, [PlatformRole::QaTester, PlatformRole::Developer], true)) {
            $this->error('Role must be qa_tester or developer.');

            return self::FAILURE;
        }
        $user = User::where('email', User::normalizeEmail((string) $this->argument('email')))->first();
        if ($user === null || $user->email_verified_at === null || ! $user->isActive()) {
            $this->error('No verified, active user with that email.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $revoked = $user->platformRoles()->where('role', $role)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $audit->record('admin.qa_role_revoked', category: AuditCategory::Admin, resource: $user, metadata: ['via' => 'console', 'role' => $role->value, 'rows' => $revoked]);
            $this->info($revoked > 0 ? 'Role revoked.' : 'User did not have that role.');

            return self::SUCCESS;
        }
        if ($user->hasPlatformRole($role)) {
            $this->info('User already has that role.');

            return self::SUCCESS;
        }
        $user->platformRoles()->create(['role' => $role, 'granted_via' => 'console', 'granted_at' => now()]);
        $audit->record('admin.qa_role_granted', category: AuditCategory::Admin, resource: $user, metadata: ['via' => 'console', 'role' => $role->value]);
        $this->info('Role granted.');
        if (config('shopbonus.admin.mfa_required')) {
            $this->warn('The Quality Center stays closed (403 mfa_required) until this user enables two-factor authentication.');
        }

        return self::SUCCESS;
    }
}
