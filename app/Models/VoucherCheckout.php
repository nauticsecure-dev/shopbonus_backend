<?php

namespace App\Models;

use App\Enums\WalletUnit;
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
 * @property WalletUnit $wallet_unit
 * @property int|null $price_points
 * @property int $wallet_part_points
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

    protected $attributes = ['wallet_unit' => 'eur_credit', 'wallet_part_points' => 0];

    protected $hidden = ['id', 'user_id', 'offer_id', 'webshop_id', 'wallet_reservation_id', 'voucher_id', 'idempotency_key'];

    protected function casts(): array
    {
        return ['wallet_unit' => WalletUnit::class, 'price_points' => 'integer', 'wallet_part_points' => 'integer', 'price_minor' => 'integer', 'wallet_part_minor' => 'integer', 'payment_part_minor' => 'integer',
            'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $checkout) {
            if ($checkout->isDirty(['user_id', 'offer_id', 'webshop_id', 'idempotency_key', 'wallet_unit',
                'price_minor', 'wallet_part_minor', 'payment_part_minor', 'currency', 'price_points', 'wallet_part_points'])) {
                throw new \LogicException('Checkout price, unit and purchase identity are immutable.');
            }
        });
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
