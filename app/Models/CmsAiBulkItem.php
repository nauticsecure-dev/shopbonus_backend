<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $page_id
 * @property int $source_revision
 * @property string $source_hash
 * @property int|null $target_revision
 * @property string $status
 * @property int|null $suggestion_id
 */
class CmsAiBulkItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_revision' => 'integer', 'target_revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            if ($row->isDirty(['run_id', 'page_id', 'source_revision', 'source_hash', 'target_revision'])) {
                throw new \LogicException('Bulk source snapshots are immutable.');
            }
        });
    }
}
