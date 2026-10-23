<?php

namespace App\Http\Resources;

use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Models\WebshopVerification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Webshop as seen by a Super Admin. `detail` adds profile, verifications
 * (with decrypted review notes and evidence metadata) and acceptances.
 *
 * @mixin Webshop
 */
class AdminWebshopResource extends JsonResource
{
    private bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Webshop $w */
        $w = $this->resource;
        $owners = $w->memberships->filter(fn (WebshopMembership $m) => $m->role->value === 'owner' && $m->isActive());

        $data = [
            'id' => $w->public_id,
            'name' => $w->name,
            'platform' => $w->platform,
            'status' => $w->status->value,
            'status_reason' => $w->status_reason,
            'review_flags' => $w->review_flags ?? [],
            'ownership_verified' => $w->isOwnershipVerified(),
            'ownership_method' => $w->ownership_method,
            'verified_domain' => $w->verified_domain,
            'website_domain' => $w->businessProfile?->website_domain,
            'owners' => $owners->map(fn (WebshopMembership $m) => [
                'id' => $m->user->public_id, 'first_name' => $m->user->first_name, 'email' => $m->user->email,
            ])->values()->all(),
            'review_requested_at' => $w->review_requested_at?->toIso8601String(),
            'approved_at' => $w->approved_at?->toIso8601String(),
            'created_at' => $w->created_at->toIso8601String(),
        ];

        if (! $this->detail) {
            return $data;
        }

        $profile = $w->businessProfile;

        return $data + [
            'business_profile' => $profile === null ? null : (new BusinessProfileResource($profile->setRelation('webshop', $w)))->toArray($request),
            'verifications' => $w->verifications->sortByDesc('id')->values()->map(fn (WebshopVerification $v) => [
                ...(new VerificationResource($v))->toArray($request),
                'notes' => $v->evidence['notes'] ?? null,
                'evidence_files' => array_map(fn (array $f) => ['id' => $f['id'], 'name' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size']],
                    $v->evidence['files'] ?? []),
            ])->all(),
            'agreement_acceptances' => $w->agreementAcceptances->sortByDesc('id')->values()->map(fn ($a) => [
                'id' => $a->public_id, 'version' => $a->version, 'document_hash' => $a->document_hash,
                'signatory_name' => $a->signatory_name, 'accepted_at' => $a->accepted_at->toIso8601String(),
            ])->all(),
        ];
    }
}
