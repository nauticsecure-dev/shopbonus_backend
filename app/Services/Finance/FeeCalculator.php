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

        // Fixed vouchers have a known reward. Percentage vouchers estimate the
        // applicable reward at the minimum eligible order, capped by max discount
        // (Trello #9 §5). Funding reservations still use the configured basis.
        if ($terms->discount_type === 'free_shipping') {
            return [
                'applies' => true,
                'available' => true,
                'basis' => 'free_shipping',
                'estimated_maximum' => false,
                'currency' => $terms->currency,
                'fee_rate_bps' => $this->feeRateBps(),
                'per_voucher' => ['reward_minor' => 0, 'fee_minor' => 0, 'merchant_commitment_minor' => 0],
                'allocation' => $terms->allocation_limit === null ? null : [
                    'vouchers' => $terms->allocation_limit,
                    'merchant_commitment_minor' => 0,
                ],
                'note' => 'Free shipping has no reward amount. Nothing is charged when configuring an offer.',
            ];
        }

        if ($terms->discount_type === 'fixed') {
            $reward = $terms->discount_value;
            $basis = 'fixed_value';
            $estimated = false;
        } else {
            [$reward, $basis, $estimated] = $this->percentageReward($terms);
        }

        if ($reward === null) {
            return ['applies' => true, 'available' => false, 'reason' => 'maximum_discount_required'];
        }

        $fee = $this->feeOnReward($reward);
        $perVoucher = $reward + $fee;

        return [
            'applies' => true,
            'available' => true,
            'basis' => $basis,
            'estimated_maximum' => $estimated || $basis === 'maximum_discount',
            'currency' => $terms->currency,
            'fee_rate_bps' => $this->feeRateBps(),
            'per_voucher' => ['reward_minor' => $reward, 'fee_minor' => $fee, 'merchant_commitment_minor' => $perVoucher],
            'allocation' => $terms->allocation_limit === null ? null : [
                'vouchers' => $terms->allocation_limit,
                'merchant_commitment_minor' => $perVoucher * $terms->allocation_limit,
            ],
            'note' => 'Estimated maximum commitment preview only. Nothing is charged when configuring an offer; funding and reservations use the merchant wallet.',
        ];
    }

    /**
     * @return array{0: int|null, 1: string, 2: bool}
     */
    private function percentageReward(OfferVersion $terms): array
    {
        if ($terms->min_order_minor !== null && $terms->discount_value > 0) {
            $atMinOrder = intdiv($terms->min_order_minor * $terms->discount_value + 5000, 10000);
            if ($terms->max_discount_minor !== null) {
                $atMinOrder = min($atMinOrder, $terms->max_discount_minor);
            }

            return [$atMinOrder, 'eligible_order_capped', true];
        }

        return [$terms->max_discount_minor, 'maximum_discount', true];
    }
}
