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
 * @property string|null $request_key
 * @property string|null $provider_idempotency_key
 * @property string $creation_state
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

    protected $hidden = ['id', 'webshop_id', 'created_by', 'credit_entry_id', 'request_key', 'provider_idempotency_key'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'creation_attempted_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $t) {
            foreach (['webshop_id', 'created_by', 'request_key', 'provider_idempotency_key', 'amount_minor', 'currency', 'public_id'] as $field) {
                if ($t->isDirty($field)) {
                    throw new \LogicException('Top-up request identity is immutable.');
                }
            }
        });
    }
}
