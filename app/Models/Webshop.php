<?php

namespace App\Models;

use App\Enums\WebshopStatus;
use App\Services\Discovery\DiscoveryCache;
use Carbon\CarbonImmutable;
use Database\Factories\WebshopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A webshop and its isolated workspace.
 *
 * `status`, approval and creator fields are not fillable: they change only
 * through server-side workflows.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string|null $platform
 * @property WebshopStatus $status
 * @property string|null $status_reason
 * @property int $created_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $ownership_verified_at
 * @property string|null $ownership_method
 * @property string|null $verified_domain
 * @property CarbonImmutable|null $review_requested_at
 * @property array<string, mixed>|null $review_flags
 * @property array<string, mixed>|null $answer24_settings
 * @property-read BusinessProfile|null $businessProfile
 * @property-read WebshopPublicProfile|null $publicProfile
 * @property CarbonImmutable $created_at
 */
class Webshop extends Model
{
    /** @use HasFactory<WebshopFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'platform'];

    protected $hidden = ['id', 'created_by', 'approved_by'];

    protected $attributes = [
        'status' => 'draft',
        'ownership_verified_at' => null,
        'ownership_method' => null,
        'verified_domain' => null,
        'review_requested_at' => null,
        'review_flags' => null,
        'rejected_at' => null,
        'answer24_settings' => null,
    ];

    protected static function booted(): void
    {
        // Discovery shows only approved webshops: a status change must reach the public cache.
        static::saved(function (Webshop $webshop) {
            if ($webshop->wasChanged(['status', 'name'])) {
                DiscoveryCache::flush();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => WebshopStatus::class,
            'approved_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'ownership_verified_at' => 'immutable_datetime',
            'review_requested_at' => 'immutable_datetime',
            'review_flags' => 'array',
            'answer24_settings' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<WebshopConnection, $this> */
    public function connections(): HasMany
    {
        return $this->hasMany(WebshopConnection::class);
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** @return HasMany<WebshopMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WebshopMembership::class);
    }

    /** @return HasOne<WebshopPublicProfile, $this> */
    public function publicProfile(): HasOne
    {
        return $this->hasOne(WebshopPublicProfile::class);
    }

    /** @return HasOne<BusinessProfile, $this> */
    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    /** @return HasMany<AgreementAcceptance, $this> */
    public function agreementAcceptances(): HasMany
    {
        return $this->hasMany(AgreementAcceptance::class);
    }

    /** @return HasMany<WebshopVerification, $this> */
    public function verifications(): HasMany
    {
        return $this->hasMany(WebshopVerification::class);
    }

    public function isOwnershipVerified(): bool
    {
        return $this->ownership_verified_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
