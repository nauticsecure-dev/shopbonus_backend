<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $archived_at
 * @property int $id
 * @property string $public_id
 * @property string $path
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property string|null $original_path
 * @property string|null $original_hash
 * @property string|null $original_mime
 * @property int $media_revision
 * @property int|null $active_recipe_id
 * @property int $uploaded_by
 */
class CmsAsset extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'path', 'original_path', 'uploaded_by', 'archived_by'];

    protected $attributes = ['media_revision' => 0];

    protected function casts(): array
    {
        return ['media_revision' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $a) {
            if ($a->isDirty(['path', 'mime', 'width', 'height', 'uploaded_by', 'original_path', 'original_hash', 'original_mime'])) {
                throw new \LogicException('Media source and compatibility files are immutable.');
            }
        });
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
