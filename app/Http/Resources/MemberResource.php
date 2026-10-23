<?php

namespace App\Http\Resources;

use App\Enums\WorkspacePermission;
use App\Models\WebshopMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace member for the team list. Expects `user` loaded.
 *
 * @mixin WebshopMembership
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebshopMembership $membership */
        $membership = $this->resource;

        return [
            'id' => $membership->user->public_id,
            'first_name' => $membership->user->first_name,
            'email' => $membership->user->email,
            'role' => $membership->role->value,
            'permissions' => array_map(fn (WorkspacePermission $p) => $p->value, $membership->effectivePermissions()),
            'granted_permissions' => $membership->permissions ?? [],
            'status' => $membership->status->value,
            'joined_at' => $membership->joined_at?->toIso8601String(),
            'last_activity_at' => $membership->user->last_login_at?->toIso8601String(),
        ];
    }
}
