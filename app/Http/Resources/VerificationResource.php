<?php

namespace App\Http\Resources;

use App\Enums\VerificationMethod;
use App\Models\WebshopVerification;
use App\Services\Onboarding\OwnershipVerification;
use App\Support\Mask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ownership verification as shown to the owner, with method-specific
 * instructions. Codes, hashes and evidence are never returned.
 *
 * @mixin WebshopVerification
 */
class VerificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebshopVerification $v */
        $v = $this->resource;

        return [
            'id' => $v->public_id,
            'method' => $v->method->value,
            'status' => $v->status->value === 'pending' && $v->isExpired() ? 'expired' : $v->status->value,
            'domain' => $v->domain,
            'instructions' => match ($v->method) {
                VerificationMethod::DnsTxt => [
                    'record_type' => 'TXT',
                    'record_name' => OwnershipVerification::DNS_PREFIX.'.'.$v->domain,
                    'record_value' => $v->challenge_value,
                ],
                VerificationMethod::WebsiteFile => [
                    'url' => 'https://'.$v->domain.OwnershipVerification::FILE_PATH,
                    'file_contents' => $v->challenge_value,
                ],
                VerificationMethod::DomainEmail => [
                    'sent_to' => $v->email === null ? null : Mask::email($v->email),
                ],
                VerificationMethod::ManualReview => ['message' => 'A Shopbonus administrator will review the submitted information.'],
            },
            'attempt_count' => $v->attempt_count,
            'expires_at' => $v->expires_at?->toIso8601String(),
            'verified_at' => $v->verified_at?->toIso8601String(),
            'failure_reason' => $v->failure_reason,
            'created_at' => $v->created_at->toIso8601String(),
        ];
    }
}
