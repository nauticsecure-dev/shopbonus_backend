<?php

namespace App\Services\Affiliate;

use App\Enums\WalletUnit;
use App\Models\AffiliateProgram;
use App\Models\AffiliateRewardRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Shared eligibility and calculation for network rewards and public estimates. */
class AffiliateRewardPolicy
{
    public const SOURCE = 'affiliate_network';

    public function rule(?CarbonImmutable $at = null, ?int $webshopId = null): ?AffiliateRewardRule
    {
        return AffiliateRewardRule::current($at, $webshopId, self::SOURCE);
    }

    public function fromRules(Collection $rules, int $webshopId): ?AffiliateRewardRule
    {
        $eligible = $rules->filter(fn ($r) => $r->commission_source === null || $r->commission_source === self::SOURCE);

        return $eligible->firstWhere('webshop_id', $webshopId) ?? $eligible->first(fn ($r) => $r->webshop_id === null);
    }

    public function reward(?AffiliateRewardRule $rule, int $commissionMinor): int
    {
        return $rule?->rewardFor($commissionMinor) ?? 0;
    }

    public function publicRate(?AffiliateProgram $program, ?AffiliateRewardRule $rule): ?float
    {
        // A flat percentage cannot represent minimums, caps or points.
        if ($program === null || ! $program->active || ! $program->cashback_allowed || $program->currency !== 'EUR'
            || $program->commission_type !== 'percentage' || $rule === null || $rule->unit !== WalletUnit::EurCredit
            || $rule->min_commission_minor > 0 || $rule->max_reward_minor !== null || $rule->share_bps <= 0) {
            return null;
        }

        return ($program->commission_value * $rule->share_bps) / 100_000_000;
    }
}
