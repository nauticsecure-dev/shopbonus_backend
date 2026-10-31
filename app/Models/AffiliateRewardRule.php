<?php

namespace App\Models;

use App\Enums\WalletUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Versioned rule turning an eligible commission into a wallet reward
 * (Trello #19 "Points earning rules"). Integer arithmetic, rounded down.
 *
 * @property int $id
 * @property int $version
 * @property WalletUnit $unit
 * @property int $share_bps
 * @property int $min_commission_minor
 * @property int|null $max_reward_minor
 * @property int|null $review_above_minor
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $active
 * @property int $created_by
 */
class AffiliateRewardRule extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['id', 'created_by'];

    protected function casts(): array
    {
        return ['unit' => WalletUnit::class, 'share_bps' => 'integer', 'min_commission_minor' => 'integer', 'max_reward_minor' => 'integer',
            'review_above_minor' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'active' => 'boolean',
            'cashback_approved' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $rule) {
            if ($rule->isDirty(['version', 'unit', 'share_bps', 'min_commission_minor', 'max_reward_minor', 'review_above_minor', 'starts_at', 'ends_at', 'created_by'])) {
                throw new \LogicException('Reward rule terms are immutable; create a new version.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Historical reward rules must be preserved.'));
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        // Only persist columns that can rehydrate via new AffiliateRewardRule($snapshot).
        return $this->only(['version', 'share_bps', 'min_commission_minor', 'max_reward_minor', 'review_above_minor', 'webshop_id', 'commission_source', 'cashback_approved'])
            + ['unit' => $this->unit->value];
    }

    /**
     * The rule in force at $at, or null (no rule → no reward).
     * Prefer a webshop-scoped override when one exists; otherwise the global rule.
     */
    public static function current(?CarbonImmutable $at = null, ?int $webshopId = null, ?string $commissionSource = null): ?self
    {
        $at ??= CarbonImmutable::now();
        $base = fn () => self::query()->where('active', true)
            ->where(fn ($q) => $q->whereNull('cashback_approved')->orWhere('cashback_approved', true))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));

        if ($webshopId !== null) {
            $scoped = $base()
                ->where('webshop_id', $webshopId)
                ->when($commissionSource !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('commission_source')->orWhere('commission_source', $commissionSource)))
                ->orderByDesc('version')->first();
            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $base()->whereNull('webshop_id')->orderByDesc('version')->first();
    }

    public function consumerShare(): float
    {
        return $this->share_bps / 10_000;
    }

    public function shopbonusShare(): float
    {
        return (10_000 - $this->share_bps) / 10_000;
    }

    public function rewardFor(int $commissionMinor): int
    {
        if ($commissionMinor < $this->min_commission_minor) {
            return 0;
        }
        $reward = intdiv($commissionMinor * $this->share_bps, 10_000);

        return $this->max_reward_minor === null ? $reward : min($reward, $this->max_reward_minor);
    }

    /**
     * Rehydrate an immutable terms snapshot without mass-assignment of computed keys.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromEvidence(array $snapshot): self
    {
        return new self(array_intersect_key($snapshot, array_flip([
            'version', 'share_bps', 'min_commission_minor', 'max_reward_minor', 'review_above_minor',
            'webshop_id', 'commission_source', 'cashback_approved', 'unit',
        ])));
    }
}
