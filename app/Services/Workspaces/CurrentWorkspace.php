<?php

namespace App\Services\Workspaces;

use App\Enums\WorkspacePermission;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use LogicException;

/**
 * The workspace resolved for this request by ResolveWorkspace. Scoped to the
 * request; controllers and services read the webshop from here, never from
 * client input.
 */
class CurrentWorkspace
{
    private ?Webshop $webshop = null;

    private ?WebshopMembership $membership = null;

    public function set(Webshop $webshop, WebshopMembership $membership): void
    {
        $this->webshop = $webshop;
        $this->membership = $membership;
    }

    public function isResolved(): bool
    {
        return $this->webshop !== null;
    }

    public function webshop(): Webshop
    {
        return $this->webshop ?? throw new LogicException('No workspace resolved for this request.');
    }

    public function membership(): WebshopMembership
    {
        return $this->membership ?? throw new LogicException('No workspace resolved for this request.');
    }

    public function allows(WorkspacePermission $permission): bool
    {
        return $this->membership?->allows($permission) ?? false;
    }
}
