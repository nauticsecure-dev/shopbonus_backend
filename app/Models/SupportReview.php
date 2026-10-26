<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $message_id
 * @property string $trigger
 * @property string|null $outcome
 * @property string|null $note
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 */
class SupportReview extends Model
{
    use HasUlids;

    protected $table = 'support_reviews';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'webshop_id', 'conversation_id', 'message_id', 'request_key', 'request_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['note' => 'encrypted', 'reviewed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $review) {
            if ($review->isDirty(['public_id', 'message_id', 'trigger']) || $review->getOriginal('reviewed_at') !== null) {
                throw new \LogicException('Resolved support reviews are immutable.');
            }
        });
    }
}
