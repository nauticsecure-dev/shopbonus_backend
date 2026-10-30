<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A consumer's purchase of a wallet_purchase offer. State:
 * pending_payment → completed | failed | expired; paid but not fulfillable
 * → paid_unfulfilled (→ refund_pending → refunded, or back to paid_unfulfilled
 * when Mollie fails the refund).
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int $offer_id
 * @property int $webshop_id
 * @property string $idempotency_key
 * @property string $state
 * @property int $price_minor
 * @property int $wallet_part_minor
 * @property int $payment_part_minor
 * @property string $currency
 * @property int|null $wallet_reservation_id
 * @property int|null $voucher_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $failure_reason
 * @property CarbonImmutable $created_at
 * @property-read VoucherCheckoutPayment|null $payment
 */
class VoucherCheckout extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'offer_id', 'webshop_id', 'wallet_reservation_id', 'voucher_id', 'idempotency_key'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'wallet_part_minor' => 'integer', 'payment_part_minor' => 'integer',
            'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return HasOne<VoucherCheckoutPayment, $this> */
    public function payment(): HasOne
    {
        return $this->hasOne(VoucherCheckoutPayment::class, 'checkout_id');
    }
}
