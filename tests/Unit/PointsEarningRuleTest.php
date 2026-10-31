<?php

namespace Tests\Unit;

use App\Models\AffiliateRewardRule;
use Tests\TestCase;

class PointsEarningRuleTest extends TestCase
{
    public function test_explicit_rate_rounding_multiplier_and_caps_use_exact_arithmetic(): void
    {
        $rule = new AffiliateRewardRule(['unit' => 'points', 'version' => 1, 'program_id' => 1, 'webshop_id' => 1,
            'calculation_version' => 'points_v2', 'points_per_eur_share' => 100, 'share_bps' => 7000, 'min_commission_minor' => 0]);
        $this->assertSame(0, $rule->rewardFor(1));
        $rule->points_rounding = 'half_up';
        $this->assertSame(1, $rule->rewardFor(1));
        $rule->points_rounding = 'ceil';
        $this->assertSame(1, $rule->rewardFor(1));
        $rule->points_multiplier_bps = 20000;
        $this->assertSame(1400, $rule->rewardFor(1000));
        $rule->points_per_eur_share = 1000000;
        $rule->points_multiplier_bps = 1000000;
        $rule->max_reward_minor = 500;
        $this->assertSame(500, $rule->rewardFor(999999999999999));
        $this->assertSame(500, AffiliateRewardRule::fromEvidence($rule->evidence())->rewardFor(999999999999999));
    }

    public function test_historical_points_snapshots_keep_their_original_floor_calculation(): void
    {
        $legacy = AffiliateRewardRule::fromEvidence(['version' => 1, 'unit' => 'points', 'share_bps' => 7000, 'min_commission_minor' => 0, 'max_reward_minor' => null]);
        $this->assertSame('legacy_v1', $legacy->calculation_version);
        $this->assertSame(700, $legacy->rewardFor(1000));
        $this->assertSame(0, $legacy->rewardFor(1));
    }
}
