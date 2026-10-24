<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The merchant's funding commitment for one sponsored voucher (Trello #24
 * "one idempotent sponsorship obligation"): reward + 30% fee, reserved in the
 * merchant funding wallet at reservation, settled at redemption, released
 * when the voucher lapses.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $voucher_id
 * @property int|null $source_order_id
 * @property int $reward_minor
 * @property int $fee_minor
 * @property int $total_minor
 * @property string $basis
 * @property string $status
 * @property int $wallet_reservation_id
 * @property int|null $invoice_id
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable $created_at
 */
class SponsorshipObligation extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'voucher_id', 'source_order_id', 'wallet_reservation_id', 'invoice_id'];

    protected function casts(): array
    {
        return ['reward_minor' => 'integer', 'fee_minor' => 'integer', 'total_minor' => 'integer',
            'settled_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
