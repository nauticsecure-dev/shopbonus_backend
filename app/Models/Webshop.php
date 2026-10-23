<?php

namespace App\Models;

use App\Enums\WebshopStatus;
use Carbon\CarbonImmutable;
use Database\Factories\WebshopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A webshop and its isolated workspace.
 *
 * `status`, approval and creator fields are not fillable: they change only
 * through server-side workflows.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string|null $platform
 * @property WebshopStatus $status
 * @property string|null $status_reason
 * @property int $created_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable $created_at
 */
class Webshop extends Model
{
    /** @use HasFactory<WebshopFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'platform'];

    protected $hidden = ['id', 'created_by', 'approved_by'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => WebshopStatus::class,
            'approved_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<WebshopMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WebshopMembership::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
