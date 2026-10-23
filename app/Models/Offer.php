<?php

namespace App\Models;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Services\Discovery\DiscoveryCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string|null $slug
 * @property int $webshop_id
 * @property OfferAcquisition $acquisition
 * @property OfferStatus $status
 * @property int|null $current_version_id
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $ended_at
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read OfferVersion|null $currentVersion
 */
class Offer extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'current_version_id', 'created_by'];

    protected static function booted(): void
    {
        static::saved(fn () => DiscoveryCache::flush());
    }

    /** Readable, stable public slug: name plus a short random suffix (Trello #18). */
    public static function makeSlug(string $name): string
    {
        return (Str::limit(Str::slug($name), 60, '') ?: 'offer').'-'.Str::lower(Str::random(6));
    }

    protected function casts(): array
    {
        return [
            'acquisition' => OfferAcquisition::class,
            'status' => OfferStatus::class,
            'activated_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class, 'current_version_id');
    }

    /** @return HasMany<OfferVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(OfferVersion::class);
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }
}
