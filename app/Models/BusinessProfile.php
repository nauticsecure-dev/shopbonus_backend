<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Private business details of one webshop. Never exposed on public pages.
 *
 * @property int $id
 * @property int $webshop_id
 * @property string|null $legal_name
 * @property string|null $trading_name
 * @property string|null $website_url
 * @property string|null $website_domain
 * @property string|null $kvk_number
 * @property string|null $vat_number
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $main_contact_name
 * @property string|null $address_line
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $country_code
 * @property string $preferred_language
 * @property string|null $google_place_id
 * @property string|null $business_category
 * @property string $source
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by
 */
class BusinessProfile extends Model
{
    protected $table = 'webshop_business_profiles';

    protected $guarded = ['id', 'webshop_id', 'confirmed_at', 'confirmed_by'];

    protected $attributes = ['preferred_language' => 'nl', 'source' => 'manual'];

    /** Fields required before the profile can be confirmed. */
    public const REQUIRED = ['legal_name', 'website_url', 'contact_email', 'address_line', 'postal_code', 'city', 'country_code'];

    protected function casts(): array
    {
        return [
            'contact_email' => 'encrypted',
            'contact_phone' => 'encrypted',
            'main_contact_name' => 'encrypted',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function missingFields(): array
    {
        $missing = array_values(array_filter(self::REQUIRED, fn (string $field) => blank($this->getAttribute($field))));

        // NL businesses need a KvK number (Trello #16 "Chamber of Commerce/KvK number, if applicable").
        if ($this->country_code === 'NL' && blank($this->kvk_number)) {
            $missing[] = 'kvk_number';
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->missingFields() === [];
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }
}
