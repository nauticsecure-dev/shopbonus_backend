<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $setting_id
 * @property int $version
 * @property mixed $value
 * @property string $checksum
 * @property string $status
 * @property int $created_by
 * @property int|null $reviewed_by
 * @property int|null $rollback_of_id
 * @property string $reason
 * @property string|null $review_note
 * @property CarbonImmutable|null $published_at
 */
class PlatformSettingVersion extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['value' => 'json', 'published_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            foreach (['setting_id', 'version', 'value', 'checksum', 'created_by', 'reason', 'rollback_of_id', 'public_id'] as $field) {
                if ($version->isDirty($field)) {
                    throw new \LogicException('Setting version evidence is immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Setting versions must be preserved.'));
    }
}
