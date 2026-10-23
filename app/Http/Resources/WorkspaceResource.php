<?php

namespace App\Http\Resources;

use App\Enums\WorkspacePermission;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A webshop as seen by one of its members. Expects the member's own
 * membership loaded as the only `memberships` relation.
 *
 * @mixin Webshop
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Webshop $webshop */
        $webshop = $this->resource;
        /** @var WebshopMembership|null $membership */
        $membership = $webshop->relationLoaded('memberships') ? $webshop->memberships->first() : null;

        return [
            'id' => $webshop->public_id,
            'name' => $webshop->name,
            'platform' => $webshop->platform,
            'status' => $webshop->status->value,
            'commercially_active' => $webshop->status->isCommerciallyActive(),
            'is_current' => $request->user()?->current_webshop_id === $webshop->id,
            'membership' => $membership === null ? null : [
                'role' => $membership->role->value,
                'permissions' => array_map(fn (WorkspacePermission $p) => $p->value, $membership->effectivePermissions()),
                'joined_at' => $membership->joined_at?->toIso8601String(),
            ],
            'created_at' => $webshop->created_at->toIso8601String(),
        ];
    }
}
