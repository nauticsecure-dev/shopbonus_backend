<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property string $email
 * @property MembershipRole $role
 * @property array<mixed>|null $permissions
 * @property string $token_hash
 * @property InvitationStatus $status
 * @property int $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $last_sent_at
 * @property int $resend_count
 * @property int|null $accepted_by
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property-read Webshop $webshop
 * @property-read User $inviter
 */
class WebshopInvitation extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'token_hash'];

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => InvitationStatus::class,
            'permissions' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function isOpen(): bool
    {
        return $this->status === InvitationStatus::Pending && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
