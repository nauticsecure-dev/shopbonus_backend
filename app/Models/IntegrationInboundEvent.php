<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Replay guard for signed partner requests: one row per (client, event ID).
 *
 * @property int $id
 * @property int $client_id
 * @property string $event_id
 * @property string $body_hash
 * @property string $route
 * @property CarbonImmutable $received_at
 */
class IntegrationInboundEvent extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }
}
