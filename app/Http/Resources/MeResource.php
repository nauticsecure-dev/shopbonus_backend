<?php

namespace App\Http\Resources;

use App\Models\User;
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
            // Server-owned roles and workspace memberships arrive with B03.
            'roles' => [],
            'workspaces' => [],
            'destination' => app(DestinationResolver::class)->for($user)->value,
        ];
    }
}
