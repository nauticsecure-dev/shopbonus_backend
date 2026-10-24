<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CMS page (Trello #5) with one localized entry per locale.
 *
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property string $type
 * @property string $audience
 * @property CarbonImmutable|null $archived_at
 * @property int $created_by
 */
class CmsPage extends Model
{
    use HasUlids;

    public const TYPES = ['privacy', 'terms', 'cookie_policy', 'help', 'faq', 'info', 'landing'];

    /** Legal pages need a version, an effective date and explicit approval to publish. */
    public const LEGAL = ['privacy', 'terms', 'cookie_policy'];

    protected $guarded = ['id'];

    protected $hidden = ['id', 'created_by'];

    protected $attributes = ['audience' => 'public'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return HasMany<CmsPageLocale, $this> */
    public function locales(): HasMany
    {
        return $this->hasMany(CmsPageLocale::class, 'page_id');
    }
}
