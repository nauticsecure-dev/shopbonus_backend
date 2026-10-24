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
            'review_above_minor' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'active' => 'boolean'];
    }

    /** The rule in force at $at, or null (no rule → no reward). */
    public static function current(?CarbonImmutable $at = null): ?self
    {
        $at ??= CarbonImmutable::now();

        return self::where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->orderByDesc('version')->first();
    }

    public function rewardFor(int $commissionMinor): int
    {
        if ($commissionMinor < $this->min_commission_minor) {
            return 0;
        }
        $reward = intdiv($commissionMinor * $this->share_bps, 10_000);

        return $this->max_reward_minor === null ? $reward : min($reward, $this->max_reward_minor);
    }
}
