<?php

namespace App\Services\Onboarding\Directory;

/**
 * Business lookup for prefilling onboarding forms. Results are suggestions
 * only: they never prove ownership or grant access (Trello #16).
 */
interface BusinessDirectory
{
    /**
     * @return list<array{place_id: string, name: string, description: string}>
     */
    public function search(string $query, string $countryCode): array;

    /**
     * @return array{place_id: string, name: string|null, formatted_address: string|null, address_line: string|null,
     *               postal_code: string|null, city: string|null, country_code: string|null, website_url: string|null,
     *               phone: string|null, category: string|null}
     */
    public function details(string $placeId): array;
}
