<?php

namespace App\Http\Resources;

use App\Enums\MembershipStatus;
use App\Models\User;
use App\Models\WebshopMembership;
use App\Services\Identity\DestinationResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal identity for the signed-in user (GET /api/v1/me). Kept small: no
 * internal IDs, no authorization results cached client-side as truth.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->public_id,
            'first_name' => $user->first_name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'status' => $user->status->value,
            'onboarding_intent' => $user->onboarding_intent->value,
            'locale' => $user->preferredLocale(),
            // Server-owned platform roles and active workspace memberships.
            'roles' => $user->platformRoles()->whereNull('revoked_at')->pluck('role')->map->value->values()->all(),
            'workspaces' => $user->memberships()
                ->where('status', MembershipStatus::Active)
                ->with('webshop:id,public_id,name,status')
                ->get()
                ->map(fn (WebshopMembership $m) => [
                    'id' => $m->webshop->public_id,
                    'name' => $m->webshop->name,
                    'status' => $m->webshop->status->value,
                    'role' => $m->role->value,
                    'is_current' => $user->current_webshop_id === $m->webshop_id,
                ])
                ->sortBy('name')->values()->all(),
            'destination' => app(DestinationResolver::class)->for($user)->value,
        ];
    }
}
