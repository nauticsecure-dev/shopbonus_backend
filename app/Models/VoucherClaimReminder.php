<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A granted reminder slot (day 30, 60 or 80) for one claim. Unique per day.
 *
 * @property int $id
 * @property int $claim_id
 * @property int $day
 * @property CarbonImmutable $granted_at
 */
class VoucherClaimReminder extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['day' => 'integer', 'granted_at' => 'immutable_datetime'];
    }
}
