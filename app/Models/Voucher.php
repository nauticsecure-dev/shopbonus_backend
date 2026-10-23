<?php

namespace App\Models;

use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one consumer voucher model (Trello #23). `terms_snapshot` freezes the
 * terms shown to the consumer at reservation; `offer_version_id` points at
 * the exact contractual version.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $offer_id
 * @property int $offer_version_id
 * @property int $voucher_code_id
 * @property VoucherOrigin $origin
 * @property VoucherStatus $status
 * @property int|null $consumer_user_id
 * @property int|null $source_order_id
 * @property string $idempotency_key
 * @property array<string, mixed> $terms_snapshot
 * @property CarbonImmutable|null $reserved_at
 * @property CarbonImmutable|null $claim_expires_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $redeemed_at
 * @property int|null $redeemed_order_id
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property-read VoucherCode $code
 * @property-read OfferVersion $offerVersion
 */
class Voucher extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'offer_id', 'offer_version_id', 'voucher_code_id', 'consumer_user_id', 'source_order_id', 'redeemed_order_id', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'origin' => VoucherOrigin::class,
            'status' => VoucherStatus::class,
            'terms_snapshot' => 'array',
            'reserved_at' => 'immutable_datetime',
            'claim_expires_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<VoucherCode, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(VoucherCode::class, 'voucher_code_id');
    }

    /** @return BelongsTo<User, $this> */
    public function consumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumer_user_id');
    }

    /** @return BelongsTo<OfferVersion, $this> */
    public function offerVersion(): BelongsTo
    {
        return $this->belongsTo(OfferVersion::class);
    }
}
