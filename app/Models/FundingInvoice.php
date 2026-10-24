<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Monthly statement of settled sponsored rewards and fees for one webshop.
 * A draft until it is pushed to Moneybird (not connected: OPEN-FINANCIAL-LEGAL).
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property string $period
 * @property int $reward_minor
 * @property int $fee_minor
 * @property int $lines
 * @property string $status
 * @property string|null $external_ref
 * @property CarbonImmutable $created_at
 */
class FundingInvoice extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id'];

    protected function casts(): array
    {
        return ['reward_minor' => 'integer', 'fee_minor' => 'integer', 'lines' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
