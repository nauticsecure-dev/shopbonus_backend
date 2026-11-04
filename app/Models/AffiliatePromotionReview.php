<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property string $public_id
 * @property int $offer_id
 * @property int $program_id
 * @property int $offer_version_id
 * @property array<string,mixed> $evidence
 * @property array<string,mixed> $normalized
 * @property string $status
 * @property int|null $published_version_id
 */
class AffiliatePromotionReview extends Model
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
        return ['evidence' => 'array', 'normalized' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->isDirty(['offer_id', 'program_id', 'offer_version_id', 'evidence', 'normalized', 'created_by', 'public_id']) || $r->getOriginal('status') !== 'pending') {
                throw new \LogicException('Promotion evidence and completed reviews are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Promotion reviews cannot be deleted.'));
    }
}
