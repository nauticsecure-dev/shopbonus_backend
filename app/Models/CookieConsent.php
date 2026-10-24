<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One consent decision (append-only). The latest row per consent ID is the
 * visitor's current choice.
 *
 * @property int $id
 * @property string $consent_id
 * @property int|null $user_id
 * @property string $policy_version
 * @property array{necessary: bool, preferences: bool, analytics: bool, marketing: bool} $choices
 * @property string $action
 * @property string|null $ip_hash
 * @property CarbonImmutable $created_at
 */
class CookieConsent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['choices' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
