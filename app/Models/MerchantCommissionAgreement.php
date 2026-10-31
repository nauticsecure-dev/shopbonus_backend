<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versioned direct-merchant commission + revenue split. Consumer and
 * ShopBonus shares must total 10000 bps. Historical versions are immutable.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int $version
 * @property string $commission_type
 * @property int|null $commission_rate_bps
 * @property int|null $commission_fixed_minor
 * @property int $consumer_share_bps
 * @property int $shopbonus_share_bps
 * @property bool $cashback_approved
 * @property bool $active
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 */
class MerchantCommissionAgreement extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'commission_rate_bps' => 'integer',
            'commission_fixed_minor' => 'integer',
            'consumer_share_bps' => 'integer',
            'shopbonus_share_bps' => 'integer',
            'cashback_approved' => 'boolean',
            'active' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $a) {
            if ($a->isDirty(['webshop_id', 'version', 'commission_type', 'commission_rate_bps', 'commission_fixed_minor', 'consumer_share_bps', 'shopbonus_share_bps'])) {
                throw new \LogicException('Commission agreement terms are immutable; create a new version.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Historical commission agreements must be preserved.'));
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsTo<Webshop, $this> */
    public function webshop(): BelongsTo
    {
        return $this->belongsTo(Webshop::class);
    }

    public static function current(int $webshopId, ?CarbonImmutable $at = null): ?self
    {
        $at ??= CarbonImmutable::now();

        return self::where('webshop_id', $webshopId)->where('active', true)->where('cashback_approved', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->orderByDesc('version')->first();
    }

    public function commissionForOrder(int $orderAmountMinor): int
    {
        return match ($this->commission_type) {
            'fixed' => (int) $this->commission_fixed_minor,
            default => intdiv($orderAmountMinor * (int) $this->commission_rate_bps, 10_000),
        };
    }

    public function consumerCreditFor(int $commissionMinor): int
    {
        return intdiv($commissionMinor * $this->consumer_share_bps, 10_000);
    }

    /** Effective cashback rate on eligible order amount (percentage agreements). */
    public function consumerCashbackRate(): ?float
    {
        if ($this->commission_type !== 'percentage' || $this->commission_rate_bps === null) {
            return null;
        }

        return ($this->commission_rate_bps * $this->consumer_share_bps) / 100_000_000;
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return [
            'agreement_id' => $this->public_id,
            'version' => $this->version,
            'commission_source' => 'direct_merchant',
            'commission_type' => $this->commission_type,
            'commission_rate' => $this->commission_rate_bps === null ? null : $this->commission_rate_bps / 10_000,
            'commission_rate_bps' => $this->commission_rate_bps,
            'commission_fixed_minor' => $this->commission_fixed_minor,
            'consumer_share' => $this->consumer_share_bps / 10_000,
            'shopbonus_share' => $this->shopbonus_share_bps / 10_000,
            'consumer_share_bps' => $this->consumer_share_bps,
            'shopbonus_share_bps' => $this->shopbonus_share_bps,
            'consumer_cashback_rate' => $this->consumerCashbackRate(),
            'cashback_approved' => $this->cashback_approved,
        ];
    }
}
