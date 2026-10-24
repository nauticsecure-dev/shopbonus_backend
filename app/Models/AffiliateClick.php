<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An authorised "Visit webshop" click. Creates no reward by itself.
 *
 * @property int $id
 * @property string $reference
 * @property int|null $user_id
 * @property int $webshop_id
 * @property int $program_id
 * @property string|null $ip_hash
 * @property CarbonImmutable $created_at
 */
class AffiliateClick extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
