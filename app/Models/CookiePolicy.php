<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A cookie policy version (Trello #5): categories with allowlisted
 * trackers and the localized banner text. draft → published → retired.
 *
 * @property int $id
 * @property string $public_id
 * @property string $version
 * @property string $status
 * @property list<array{key: string, required: bool, trackers: list<array<string, string>>}> $categories
 * @property array<string, array<string, string>> $texts
 * @property bool $requires_reconsent
 * @property int $created_by
 * @property int|null $published_by
 * @property CarbonImmutable|null $published_at
 */
class CookiePolicy extends Model
{
    use HasUlids;

    public const CATEGORIES = ['necessary', 'preferences', 'analytics', 'marketing'];

    protected $guarded = ['id'];

    protected $hidden = ['id', 'created_by', 'published_by'];

    protected function casts(): array
    {
        return ['categories' => 'array', 'texts' => 'array', 'requires_reconsent' => 'boolean', 'published_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public static function current(): ?self
    {
        return self::where('status', 'published')->latest('published_at')->latest('id')->first();
    }
}
