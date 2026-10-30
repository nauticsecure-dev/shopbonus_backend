<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mollie payment of a checkout: only references and verified statuses,
 * never card or account details.
 *
 * @property int $id
 * @property int $checkout_id
 * @property string $provider
 * @property string $provider_ref
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string|null $checkout_url
 * @property bool $test_mode
 * @property CarbonImmutable|null $verified_at
 * @property int $refunded_minor
 * @property string|null $refund_ref
 * @property string|null $refund_status Mollie refund status; null for refunds recorded before it was tracked
 * @property int|null $refund_amount_minor amount of the latest refund
 */
class VoucherCheckoutPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'refunded_minor' => 'integer', 'refund_amount_minor' => 'integer', 'test_mode' => 'boolean', 'verified_at' => 'immutable_datetime'];
    }
}
