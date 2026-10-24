<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Super Admin support session acting as another user (Trello #21
 * "Impersonation"). Managed only by SupportSessions.
 *
 * @property int $id
 * @property string $public_id
 * @property int $admin_user_id
 * @property int $target_user_id
 * @property string $reason
 * @property int|null $token_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $ended_reason ended_by_admin | ended_by_session | replaced | expired
 * @property CarbonImmutable $created_at
 * @property-read User $admin
 * @property-read User $target
 */
class ImpersonationSession extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'token_id'];

    protected $attributes = ['token_id' => null, 'ended_at' => null, 'ended_reason' => null];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->ended_at !== null => 'ended',
            $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
