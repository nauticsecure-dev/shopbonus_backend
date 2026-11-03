<?php

namespace App\Models;

use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Services\Discovery\DiscoveryCache;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one consumer voucher model (Trello #23). `terms_snapshot` freezes the
 * terms shown to the consumer at reservation; `offer_version_id` points at
 * the exact contractual version.
 *
 * @property int|null $acquisition_transaction_id
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
 * @property string|null $redemption_source
 * @property int|null $redeemed_by
 * @property string|null $redemption_reference
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property-read VoucherCode $code
 * @property-read OfferVersion $offerVersion
 */
class Voucher extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'acquisition_transaction_id'];

    protected $hidden = ['id', 'acquisition_transaction_id', 'webshop_id', 'offer_id', 'offer_version_id', 'voucher_code_id', 'consumer_user_id', 'source_order_id', 'redeemed_order_id', 'idempotency_key'];

    protected static function booted(): void
    {
        static::saved(function (self $voucher) {
            if ($voucher->wasRecentlyCreated || $voucher->wasChanged(['status', 'offer_id'])) {
                DiscoveryCache::flush();
            }
        });
        static::deleted(fn () => DiscoveryCache::flush());
    }

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

    /**
     * Current consumer usability; reusable available codes remain valid.
     *
     * @param  Builder<Voucher>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', VoucherStatus::Issued)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('code', fn ($q) => $q->where(fn ($c) => $c->where('status', 'issued')->orWhere(fn ($r) => $r->where('reusable', true)->where('status', 'available')))
                ->whereNull('invalidated_at')->where('platform_validation', '!=', 'mismatch'));
    }

    public function isUsable(): bool
    {
        $code = $this->code;

        return $this->status === VoucherStatus::Issued && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($code->status->value === 'issued' || ($code->reusable && $code->status->value === 'available'))
            && $code->invalidated_at === null && $code->platform_validation !== 'mismatch';
    }

    /**
     * Only explicit, approved, non-duplicate acquisition evidence qualifies.
     *
     * @param  Builder<Voucher>  $query
     */
    public function scopeAcquisitionNetwork(Builder $query, string $network): void
    {
        $evidence = fn ($q) => $q->where('status', 'approved')->whereNull('duplicate_of_id')
            ->whereNotNull('user_id')->whereColumn('affiliate_transactions.user_id', 'vouchers.consumer_user_id')
            ->whereColumn('affiliate_transactions.webshop_id', 'vouchers.webshop_id');
        if ($network === 'other') {
            $query->whereDoesntHave('acquisitionTransaction', fn ($q) => $evidence($q));
        } else {
            $query->whereHas('acquisitionTransaction', fn ($q) => $evidence($q)->where('network', $network));
        }
    }

    /** @return BelongsTo<AffiliateTransaction, $this> */
    public function acquisitionTransaction(): BelongsTo
    {
        return $this->belongsTo(AffiliateTransaction::class, 'acquisition_transaction_id');
    }

    /** @return BelongsTo<VoucherCode, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(VoucherCode::class, 'voucher_code_id');
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
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

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
