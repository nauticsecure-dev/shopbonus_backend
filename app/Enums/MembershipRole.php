<?php

namespace App\Enums;

/**
 * Workspace roles (Trello #16). Super Admin is a platform role, never a
 * membership role.
 */
enum MembershipRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Employee = 'employee';

    /**
     * Permissions every member with this role has.
     *
     * @return list<WorkspacePermission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Owner => WorkspacePermission::cases(),
            self::Manager => array_values(array_filter(
                WorkspacePermission::cases(),
                fn (WorkspacePermission $p) => ! $p->isOwnerOnly(),
            )),
            self::Employee => [WorkspacePermission::DashboardView],
        };
    }

    /** Rank for "cannot grant a role above your own" checks. */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Manager => 2,
            self::Employee => 1,
        };
    }
}
