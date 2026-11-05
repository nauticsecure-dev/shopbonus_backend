<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property string $public_id
 * @property int $created_by
 * @property string $kind
 * @property string $source_locale
 * @property string $target_locale
 * @property string $status
 * @property int $reconcile_cursor
 * @property int $source_cursor
 * @property int $source_ceiling
 * @property int $item_limit
 */
class CmsAiBulkRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'planning', 'source_cursor' => 0, 'reconcile_cursor' => 0];

    protected function casts(): array
    {
        return ['reconcile_cursor' => 'integer', 'source_cursor' => 'integer', 'source_ceiling' => 'integer', 'item_limit' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->isDirty(['public_id', 'created_by', 'kind', 'source_locale', 'target_locale', 'source_ceiling', 'item_limit'])) {
                throw new \LogicException('Bulk planning identity is immutable.');
            }
        });
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
