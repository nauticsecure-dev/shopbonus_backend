<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $connection_id
 * @property int $user_id
 * @property int $credential_generation
 * @property string $state_hash
 * @property string $session_hash
 * @property string $config_hash
 * @property string $credential_hash
 * @property string $status
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 */
class ShopifyAuthorization extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['state_hash', 'session_hash', 'credential_hash', 'config_hash'];

    protected function casts(): array
    {
        return ['credential_generation' => 'integer', 'expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }
}
