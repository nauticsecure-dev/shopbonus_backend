<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A merchant's Mollie top-up of the funding wallet. pending → paid | failed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $state
 * @property string|null $provider_ref
 * @property string|null $checkout_url
 * @property int $created_by
 * @property int|null $credit_entry_id
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable $created_at
 */
class FundingTopUp extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'created_by', 'credit_entry_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
