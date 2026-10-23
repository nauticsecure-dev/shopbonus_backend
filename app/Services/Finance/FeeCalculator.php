<?php

namespace App\Services\Finance;

use App\Enums\OfferAcquisition;
use App\Models\OfferVersion;

/**
 * Direct merchant-funded reward economics (Trello board, card #7 §4):
 * the Shopbonus fee is a percentage ON TOP OF the reward amount, never of
 * the order value. €10 reward at 30% → €3 fee → €13 merchant commitment.
 *
 * Integer minor units only; half-up rounding to the minor unit.
 * This is the single calculation used by previews and (B12) funding.
 */
class FeeCalculator
{
    public function feeRateBps(): int
    {
        return (int) config('shopbonus.fees.direct_reward_fee_bps', 3000);
    }

    public function feeOnReward(int $rewardMinor): int
    {
        if ($rewardMinor < 0) {
            throw new \InvalidArgumentException('Reward amount cannot be negative.');
        }

        return intdiv($rewardMinor * $this->feeRateBps() + 5000, 10000);
    }

    /**
     * @return array<string, mixed>
     */
    public function offerPreview(OfferAcquisition $acquisition, OfferVersion $terms): array
    {
        if ($acquisition !== OfferAcquisition::MerchantReward) {
            return [
                'applies' => false,
                'reason' => $acquisition === OfferAcquisition::WalletPurchase ? 'consumer_funded' : 'merchant_promotion',
            ];
        }

        // Fixed vouchers have a known reward; percentage vouchers are previewed on their
        // maximum discount until the billing basis is decided (OPEN-PERCENT-REWARD-FEE-BASIS).
        [$reward, $basis] = $terms->discount_type === 'fixed'
            ? [$terms->discount_value, 'fixed_value']
            : [$terms->max_discount_minor, 'maximum_discount'];

        if ($reward === null) {
            return ['applies' => true, 'available' => false, 'reason' => 'maximum_discount_required'];
        }

        $fee = $this->feeOnReward($reward);
        $perVoucher = $reward + $fee;

        return [
            'applies' => true,
            'available' => true,
            'basis' => $basis,
            'currency' => $terms->currency,
            'fee_rate_bps' => $this->feeRateBps(),
            'per_voucher' => ['reward_minor' => $reward, 'fee_minor' => $fee, 'merchant_commitment_minor' => $perVoucher],
            'allocation' => $terms->allocation_limit === null ? null : [
                'vouchers' => $terms->allocation_limit,
                'merchant_commitment_minor' => $perVoucher * $terms->allocation_limit,
            ],
            'note' => 'Preview only. Funding and invoicing are handled by the merchant wallet; nothing is charged here.',
        ];
    }
}
