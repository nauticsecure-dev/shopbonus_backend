<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $wallet_id
 * @property int $consumer_id
 * @property int $source_entry_id
 * @property int $requested_by
 * @property int|null $reviewed_by
 * @property int|null $posted_entry_id
 * @property int $amount
 * @property string $unit
 * @property string $reason
 * @property string $verification_note
 * @property string|null $review_note
 * @property string $policy_version
 * @property string $status
 * @property string $request_key
 * @property CarbonImmutable|null $reviewed_at
 */
class AdminApprovalRequest extends Model
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
        return ['amount' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            foreach (['wallet_id', 'consumer_id', 'source_entry_id', 'requested_by', 'amount', 'unit', 'reason', 'verification_note', 'policy_version', 'request_key', 'public_id'] as $field) {
                if ($request->isDirty($field)) {
                    throw new \LogicException('Approval request evidence is immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Approval requests must be preserved.'));
    }
}
