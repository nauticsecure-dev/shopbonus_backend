<?php

namespace App\Models;

use App\Enums\VerificationMethod;
use App\Enums\VerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ownership-verification attempt for a webshop.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property VerificationMethod $method
 * @property VerificationStatus $status
 * @property string|null $domain
 * @property string|null $challenge_value
 * @property string|null $email
 * @property string|null $code_hash
 * @property int $attempt_count
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $verified_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $evidence
 * @property int $created_by
 * @property CarbonImmutable $created_at
 */
class WebshopVerification extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'code_hash', 'evidence'];

    protected $attributes = ['attempt_count' => 0];

    protected function casts(): array
    {
        return [
            'method' => VerificationMethod::class,
            'status' => VerificationStatus::class,
            'evidence' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }
}
