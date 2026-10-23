<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pending change of the login email (Trello #10 "Do not immediately replace
 * the trusted login email"). The login email only changes when the code
 * sent to `new_email` is confirmed.
 *
 * @property int $id
 * @property int $user_id
 * @property string $new_email
 * @property string $code_hash
 * @property CarbonImmutable $expires_at
 * @property int $attempt_count
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $superseded_at
 * @property CarbonImmutable $created_at
 */
class EmailChangeRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->whereNull('superseded_at');
    }
}
