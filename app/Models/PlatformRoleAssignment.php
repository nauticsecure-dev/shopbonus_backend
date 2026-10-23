<?php

namespace App\Models;

use App\Enums\PlatformRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property PlatformRole $role
 * @property string $granted_via
 * @property int|null $granted_by
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable|null $revoked_at
 */
class PlatformRoleAssignment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => PlatformRole::class,
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
