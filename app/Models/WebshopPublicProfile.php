<?php

namespace App\Models;

use App\Services\Discovery\DiscoveryCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * The merchant-approved public face of a webshop (Trello #18). Nothing here
 * is private; everything private stays on Webshop/BusinessProfile.
 *
 * @property int $id
 * @property int $webshop_id
 * @property string $slug
 * @property string $display_name
 * @property string|null $short_description
 * @property string|null $description
 * @property string|null $website_url
 * @property string|null $logo_path
 * @property string|null $logo_mime
 * @property string|null $cover_url
 * @property string|null $cover_path
 * @property string|null $cover_mime
 * @property bool $published
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $first_published_at
 * @property CarbonImmutable $updated_at
 * @property-read Webshop $webshop
 */
class WebshopPublicProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'logo_path', 'logo_mime'];

    protected $attributes = ['published' => false];

    protected static function booted(): void
    {
        static::saved(fn () => DiscoveryCache::flush());
        static::deleted(fn () => DiscoveryCache::flush());
    }

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'published_at' => 'immutable_datetime',
            'first_published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    /** @return list<string> */
    public function categories(): array
    {
        return DB::table('webshop_public_categories')->where('webshop_id', $this->webshop_id)->orderBy('category')->pluck('category')->all();
    }
}
