<?php

namespace App\Http\Resources;

use App\Models\BusinessProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Private business details for workspace members with settings.view.
 *
 * @mixin BusinessProfile
 */
class BusinessProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BusinessProfile $p */
        $p = $this->resource;

        return [
            'legal_name' => $p->legal_name,
            'trading_name' => $p->trading_name,
            'website_url' => $p->website_url,
            'website_domain' => $p->website_domain,
            'kvk_number' => $p->kvk_number,
            'vat_number' => $p->vat_number,
            'contact_email' => $p->contact_email,
            'contact_phone' => $p->contact_phone,
            'main_contact_name' => $p->main_contact_name,
            'address_line' => $p->address_line,
            'postal_code' => $p->postal_code,
            'city' => $p->city,
            'country_code' => $p->country_code,
            'preferred_language' => $p->preferred_language,
            'google_place_id' => $p->google_place_id,
            'business_category' => $p->business_category,
            'source' => $p->source,
            'confirmed' => $p->confirmed_at !== null,
            'confirmed_at' => $p->confirmed_at?->toIso8601String(),
            'missing_fields' => $p->missingFields(),
            'domain_locked' => $p->webshop->isOwnershipVerified(),
        ];
    }
}
