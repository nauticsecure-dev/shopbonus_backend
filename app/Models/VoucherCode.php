<?php

namespace App\Models;

use App\Enums\VoucherCodeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A merchant discount code in the single inventory. The value is encrypted;
 * `code_hash` (case-insensitive, per webshop) enforces uniqueness.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $offer_id
 * @property int|null $batch_id
 * @property string $code
 * @property string $code_hash
 * @property string $code_last4
 * @property VoucherCodeStatus $status
 * @property string $platform_validation
 * @property bool $reusable
 * @property int $uses_count
 * @property CarbonImmutable|null $reserved_at
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $redeemed_at
 * @property CarbonImmutable|null $invalidated_at
 * @property string|null $invalid_reason
 * @property CarbonImmutable $created_at
 */
class VoucherCode extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'offer_id', 'batch_id', 'code', 'code_hash'];

    protected $attributes = ['status' => 'available', 'platform_validation' => 'merchant_verification_required', 'uses_count' => 0, 'reusable' => false];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'status' => VoucherCodeStatus::class,
            'reusable' => 'boolean',
            'uses_count' => 'integer',
            'reserved_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public static function hashFor(int $webshopId, string $code): string
    {
        return hash_hmac('sha256', $webshopId.'|'.mb_strtoupper(trim($code)), (string) config('app.key'));
    }

    public function masked(): string
    {
        return '****'.$this->code_last4;
    }

    /** @return BelongsTo<VoucherCodeBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(VoucherCodeBatch::class, 'batch_id');
    }

    /**
     * The latest voucher holding this code (unique codes have at most one live voucher).
     *
     * @return HasOne<Voucher, $this>
     */
    public function currentVoucher(): HasOne
    {
        return $this->hasOne(Voucher::class, 'voucher_code_id')->latestOfMany();
    }

    /** @return BelongsTo<Offer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
