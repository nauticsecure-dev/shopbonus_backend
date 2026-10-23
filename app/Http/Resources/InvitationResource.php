<?php

namespace App\Http\Resources;

use App\Models\WebshopInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invitation as seen by the inviting workspace's team managers.
 *
 * @mixin WebshopInvitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebshopInvitation $invitation */
        $invitation = $this->resource;

        return [
            'id' => $invitation->public_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'permissions' => $invitation->permissions ?? [],
            'status' => $invitation->isOpen() || $invitation->status->value !== 'pending' ? $invitation->status->value : 'expired',
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'last_sent_at' => $invitation->last_sent_at->toIso8601String(),
            'resend_count' => $invitation->resend_count,
            'created_at' => $invitation->created_at->toIso8601String(),
        ];
    }
}
