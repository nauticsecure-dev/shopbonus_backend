<?php

namespace App\Services\Onboarding;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Draft-friendly business details (Trello #16 "Business search & details").
 * Partial saves are allowed; confirmation requires every required field.
 * Once ownership is verified, the website domain is locked: changing it
 * would detach the proof from the profile.
 */
class BusinessProfileService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function forWebshop(Webshop $webshop): BusinessProfile
    {
        return $webshop->businessProfile ?? (new BusinessProfile)->setRelation('webshop', $webshop);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(Webshop $webshop, User $actor, array $data, bool $confirm): BusinessProfile
    {
        return DB::transaction(function () use ($webshop, $actor, $data, $confirm) {
            $profile = BusinessProfile::where('webshop_id', $webshop->id)->lockForUpdate()->first() ?? new BusinessProfile;
            $fields = collect($data)->except(['confirm'])->all();

            if (array_key_exists('website_url', $fields)) {
                $domain = $fields['website_url'] === null ? null : DomainName::fromInput((string) $fields['website_url']);

                if ($webshop->isOwnershipVerified() && $domain !== $webshop->verified_domain) {
                    throw ApiException::conflict('The website domain is locked after ownership verification.', 'domain_locked');
                }

                $fields['website_domain'] = $domain;
            }

            foreach (['kvk_number', 'vat_number'] as $key) {
                if (isset($fields[$key])) {
                    $fields[$key] = strtoupper(preg_replace('/[\s.\-]/', '', (string) $fields[$key]) ?? '');
                }
            }

            $changed = array_keys(array_diff_assoc(
                array_map(fn ($v) => (string) $v, $fields),
                array_map(fn ($v) => (string) $v, $profile->only(array_keys($fields))),
            ));

            $profile->fill($fields);
            $profile->webshop_id = $webshop->id;

            // Any material change after confirmation requires confirming again.
            if ($changed !== [] && $profile->confirmed_at !== null) {
                $profile->confirmed_at = null;
                $profile->confirmed_by = null;
            }

            if ($confirm) {
                if (! $profile->isComplete()) {
                    throw new ApiException('Some required business details are missing.', 422, 'business_details_incomplete',
                        ['missing_fields' => $profile->missingFields()]);
                }

                $profile->confirmed_at = now()->toImmutable();
                $profile->confirmed_by = $actor->id;
            }

            $profile->save();

            $this->audit->record($confirm ? 'onboarding.business_details_confirmed' : 'onboarding.business_details_saved',
                category: AuditCategory::Workspace, resource: $profile, webshopId: $webshop->id,
                metadata: ['changed_fields' => $changed]);

            return $profile->setRelation('webshop', $webshop);
        });
    }
}
