<?php

namespace App\Models;

use App\Enums\ConsentPurpose;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property ConsentPurpose $purpose
 * @property bool $granted
 * @property string $policy_version
 * @property string $source
 * @property CarbonImmutable $recorded_at
 * @property array<string, mixed>|null $evidence
 */
class ConsentRecord extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'granted' => 'boolean',
            'recorded_at' => 'immutable_datetime',
            'evidence' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
