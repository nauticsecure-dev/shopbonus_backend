<?php

namespace App\Services\Cashback;

use App\Models\AffiliateTransaction;
use App\Models\MerchantCommissionConversion;

/**
 * Prevents duplicate wallet rewards when the same merchant order is reported
 * via affiliate network and direct-merchant conversion paths.
 */
class RewardSourceGuard
{
    public function orderReferenceHash(int $webshopId, string $orderReference): string
    {
        return hash_hmac('sha256', 'affiliate-order|'.$webshopId.'|'.$orderReference, (string) config('app.key'));
    }

    /** True when an affiliate path already credited this webshop order reference. */
    public function affiliateAlreadyCredited(int $webshopId, string $orderReference): bool
    {
        $hash = $this->orderReferenceHash($webshopId, $orderReference);

        return AffiliateTransaction::where('webshop_id', $webshopId)
            ->where('order_reference_hash', $hash)
            ->where('reward_status', 'credited')
            ->exists();
    }

    /** True when a direct-merchant conversion already credited this external order id. */
    public function directAlreadyCredited(int $webshopId, string $externalId): bool
    {
        return MerchantCommissionConversion::where('webshop_id', $webshopId)
            ->where('external_id', $externalId)
            ->where('status', 'credited')
            ->exists();
    }

    /**
     * Blocks direct credit when affiliate already paid; blocks affiliate credit when direct already paid.
     */
    public function assertDirectAllowed(int $webshopId, string $externalId): ?string
    {
        return $this->affiliateAlreadyCredited($webshopId, $externalId)
            ? 'affiliate_reward_already_credited'
            : null;
    }

    public function assertAffiliateAllowed(int $webshopId, ?string $orderReference): ?string
    {
        if ($orderReference === null || $orderReference === '') {
            return null;
        }

        return $this->directAlreadyCredited($webshopId, $orderReference)
            ? 'direct_merchant_reward_already_credited'
            : null;
    }
}
