<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WorkspacePermission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit, server-owned link between a user and a webshop.
 *
 * @property int $id
 * @property int $webshop_id
 * @property int $user_id
 * @property MembershipRole $role
 * @property array<mixed>|null $permissions Raw JSON grants; validated when read
 * @property MembershipStatus $status
 * @property int|null $granted_by
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $removed_at
 * @property-read Webshop $webshop
 * @property-read User $user
 */
class WebshopMembership extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
            'permissions' => 'array',
            'joined_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /**
     * Role defaults plus explicit grants. Owner-only permissions are only
     * ever effective for owners, whatever is stored.
     *
     * @return list<WorkspacePermission>
     */
    public function effectivePermissions(): array
    {
        $granted = array_filter(array_map(
            fn ($value) => is_string($value) ? WorkspacePermission::tryFrom($value) : null,
            $this->permissions ?? [],
        ));

        $all = array_unique([...$this->role->defaultPermissions(), ...$granted], SORT_REGULAR);

        return array_values(array_filter(
            $all,
            fn (WorkspacePermission $p) => ! $p->isOwnerOnly() || $this->role === MembershipRole::Owner,
        ));
    }

    public function allows(WorkspacePermission $permission): bool
    {
        return $this->isActive() && in_array($permission, $this->effectivePermissions(), true);
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
