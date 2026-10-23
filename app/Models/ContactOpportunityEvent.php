<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only history of Answer24 callbacks for one opportunity, including
 * the ones that were stale or duplicates (outcome).
 *
 * @property int $id
 * @property int $opportunity_id
 * @property string $status
 * @property string $outcome
 * @property string|null $remote_reference
 * @property string|null $remote_event_id
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
class ContactOpportunityEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
