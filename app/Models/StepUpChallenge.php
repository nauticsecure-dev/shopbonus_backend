<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emailed admin step-up code. Managed only by StepUpCodes.
 *
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempt_count
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable $created_at
 */
class StepUpChallenge extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected $attributes = ['attempt_count' => 0, 'consumed_at' => null];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
