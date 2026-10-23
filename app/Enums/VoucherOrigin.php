<?php

namespace App\Enums;

/**
 * Where a voucher came from. Same voucher model and inventory for all.
 */
enum VoucherOrigin: string
{
    case MerchantReward = 'merchant_reward';
    case Answer24Claim = 'answer24_claim';
    case WalletPurchase = 'wallet_purchase';
    case Promotional = 'promotional';
}
