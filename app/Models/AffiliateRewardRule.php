<?php

namespace App\Models;

use App\Enums\WalletUnit;
use App\Services\Discovery\DiscoveryCache;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Versioned rule turning an eligible commission into a wallet reward
 * (Trello #19 "Points earning rules"). Integer arithmetic, rounded down.
 *
 * @property int $id
 * @property int|null $program_id
 * @property string $calculation_version
 * @property int|null $points_per_eur_share
 * @property int $points_multiplier_bps
 * @property string $points_rounding
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

    // Snapshot hydration must not depend on a database schema cache populated
    // before migrations (or while calculating historical terms without a DB).
    protected $fillable = [
        'version', 'unit', 'share_bps', 'min_commission_minor', 'max_reward_minor', 'review_above_minor',
        'starts_at', 'ends_at', 'active', 'created_by', 'webshop_id', 'commission_source', 'cashback_approved',
        'program_id', 'calculation_version', 'points_per_eur_share', 'points_multiplier_bps', 'points_rounding',
    ];

    protected $attributes = ['calculation_version' => 'legacy_v1', 'points_multiplier_bps' => 10000, 'points_rounding' => 'floor'];

    protected $hidden = ['id', 'created_by'];

    protected function casts(): array
    {
        return ['program_id' => 'integer', 'points_per_eur_share' => 'integer', 'points_multiplier_bps' => 'integer', 'unit' => WalletUnit::class, 'share_bps' => 'integer', 'min_commission_minor' => 'integer', 'max_reward_minor' => 'integer',
            'review_above_minor' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'active' => 'boolean',
            'cashback_approved' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => DB::afterCommit(fn () => DiscoveryCache::flush()));
        static::updating(function (self $rule) {
            if ($rule->isDirty(['version', 'unit', 'share_bps', 'min_commission_minor', 'max_reward_minor', 'review_above_minor', 'starts_at', 'ends_at', 'created_by', 'webshop_id', 'commission_source', 'cashback_approved', 'program_id', 'calculation_version', 'points_per_eur_share', 'points_multiplier_bps', 'points_rounding'])) {
                throw new \LogicException('Reward rule terms are immutable; create a new version.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Historical reward rules must be preserved.'));
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        // Only persist columns that can rehydrate via new AffiliateRewardRule($snapshot).
        return $this->only(['version', 'share_bps', 'min_commission_minor', 'max_reward_minor', 'review_above_minor', 'webshop_id', 'commission_source', 'cashback_approved', 'program_id', 'calculation_version', 'points_per_eur_share', 'points_multiplier_bps', 'points_rounding'])
            + ['unit' => $this->unit->value];
    }

    /**
     * The rule in force at $at, or null (no rule → no reward).
     * Prefer a webshop-scoped override when one exists; otherwise the global rule.
     */
    public static function current(?CarbonImmutable $at = null, ?int $webshopId = null, ?string $commissionSource = null, ?int $programId = null): ?self
    {
        $at ??= CarbonImmutable::now();
        $base = fn () => self::query()->where('active', true)
            ->where(fn ($q) => $q->whereNull('cashback_approved')->orWhere('cashback_approved', true))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->when($commissionSource !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('commission_source')->orWhere('commission_source', $commissionSource)))
            ->where(fn ($q) => $q->where('unit', '!=', WalletUnit::Points->value)->orWhere(fn ($q) => $q
                ->where('calculation_version', 'points_v2')->whereNotNull('program_id')->where('points_per_eur_share', '>', 0)));

        if ($programId !== null) {
            $programRule = $base()->where('program_id', $programId)
                ->when($webshopId !== null, fn ($q) => $q->where('webshop_id', $webshopId))->orderByDesc('version')->first();
            if ($programRule !== null) {
                return $programRule;
            }
        }

        if ($webshopId !== null) {
            $scoped = $base()->whereNull('program_id')
                ->where('webshop_id', $webshopId)
                ->when($commissionSource !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('commission_source')->orWhere('commission_source', $commissionSource)))
                ->orderByDesc('version')->first();
            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $base()->whereNull('program_id')->whereNull('webshop_id')->orderByDesc('version')->first();
    }

    /** @return list<string> */
    public function activationBlockers(): array
    {
        if ($this->unit !== WalletUnit::Points) {
            return [];
        }

        return array_keys(array_filter([
            'points_program_required' => $this->program_id === null,
            'points_program_webshop_required' => $this->webshop_id === null,
            'points_rate_required' => $this->points_per_eur_share === null || $this->points_per_eur_share <= 0,
            'points_calculation_required' => $this->calculation_version !== 'points_v2',
        ]));
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
        $reward = BigInteger::of($commissionMinor)->multipliedBy($this->share_bps);
        if ($this->unit === WalletUnit::Points && $this->calculation_version === 'points_v2') {
            if ($this->program_id === null || $this->points_per_eur_share === null || $this->points_per_eur_share <= 0) {
                return 0;
            }
            $rounding = match ($this->points_rounding) {
                'half_up' => RoundingMode::HalfUp,
                'ceil' => RoundingMode::Ceiling,
                default => RoundingMode::Floor,
            };
            // Commission is in EUR cents; share and multiplier are basis points.
            $reward = $reward->multipliedBy($this->points_per_eur_share)->multipliedBy($this->points_multiplier_bps)
                ->dividedBy('10000000000', $rounding);
        } else {
            // Legacy snapshots retain the historical floor calculation exactly.
            $reward = $reward->dividedBy(10000, RoundingMode::Floor);
        }
        if ($this->max_reward_minor !== null && $reward->isGreaterThan($this->max_reward_minor)) {
            $reward = BigInteger::of($this->max_reward_minor);
        }

        return $reward->toInt();
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
            'webshop_id', 'commission_source', 'cashback_approved', 'unit', 'program_id',
            'calculation_version', 'points_per_eur_share', 'points_multiplier_bps', 'points_rounding',
        ])));
    }
}
