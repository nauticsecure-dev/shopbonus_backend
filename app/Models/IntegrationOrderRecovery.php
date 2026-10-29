<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $purpose
 * @property int|null $bulk_run_id
 * @property int $id
 * @property string $public_id
 * @property int $connection_id
 * @property int $webshop_id
 * @property int $credential_generation
 * @property string|null $provider_store_ref
 * @property string $verified_domain
 * @property string $external_order_id
 * @property string $request_key
 * @property string $status
 * @property string|null $lease_token
 * @property CarbonImmutable|null $leased_until
 * @property int $attempts
 * @property int|null $webhook_event_id
 * @property string|null $last_error_code
 */
class IntegrationOrderRecovery extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'connection_id', 'webshop_id', 'credential_generation', 'lease_token'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['bulk_run_id' => 'integer', 'credential_generation' => 'integer', 'attempts' => 'integer', 'leased_until' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
