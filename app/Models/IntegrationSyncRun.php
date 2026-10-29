<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $connection_id
 * @property int $credential_generation
 * @property string $verified_domain
 * @property string $provider_store_ref
 * @property string $request_key
 * @property string $since_at
 * @property string $until_at
 * @property string|null $cursor
 * @property string $status
 * @property bool $discovery_complete
 * @property int $pages
 * @property string|null $lease_token
 * @property CarbonImmutable|null $leased_until
 * @property int $attempts
 */
class IntegrationSyncRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['credential_generation' => 'integer', 'leased_until' => 'immutable_datetime', 'discovery_complete' => 'boolean', 'pages' => 'integer', 'attempts' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
