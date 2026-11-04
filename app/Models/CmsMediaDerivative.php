<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property int $recipe_id
 * @property string $breakpoint
 * @property string $status
 * @property string|null $path
 * @property string|null $content_hash
 * @property int|null $width
 * @property int|null $height
 * @property string|null $failure_code
 */
class CmsMediaDerivative extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['renderer' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->getOriginal('status') === 'ready' || $r->isDirty(['recipe_id', 'breakpoint'])) {
                throw new \LogicException('Ready media artifacts and their binding are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Media artifacts cannot be deleted through the model.'));
    }
}
