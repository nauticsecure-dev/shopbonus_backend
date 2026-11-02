<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D-108 workspace ownership transfer (Trello #16).
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable $created_at
 */
class OwnershipTransfer extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'from_user_id', 'to_user_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<User, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['id' => $this->public_id, 'status' => $this->isOpen() || $this->status !== 'pending' ? $this->status : 'expired',
            'from' => ['id' => $this->from->public_id, 'first_name' => $this->from->first_name],
            'to' => ['id' => $this->to->public_id, 'first_name' => $this->to->first_name],
            'expires_at' => $this->expires_at->toIso8601String(), 'decided_at' => $this->decided_at?->toIso8601String(), 'created_at' => $this->created_at->toIso8601String()];
    }
}
