<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Scoped server-to-server credential for a partner system (Answer24).
 * Provisioned only through `shopbonus:create-integration-client`; the secret
 * is shown once and stored encrypted (it is needed to verify HMACs).
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $target
 * @property string $key_id
 * @property string $secret
 * @property list<string> $scopes
 * @property bool $active
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 */
class IntegrationClient extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'scopes' => 'array',
            'active' => 'boolean',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function isUsable(): bool
    {
        return $this->active && $this->revoked_at === null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
