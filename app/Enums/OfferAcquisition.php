<?php

namespace App\Enums;

/**
 * How a consumer obtains a voucher of this offer (Trello #7 §3 A/B/C).
 * One voucher system; only acquisition and funding differ.
 */
enum OfferAcquisition: string
{
    // A. Earned by an eligible verified purchase, used on a later purchase (merchant-funded, incl. Answer24 claims).
    case MerchantReward = 'merchant_reward';
    // B. Bought with consumer wallet credit, optionally topped up via Mollie.
    case WalletPurchase = 'wallet_purchase';
    // C. An existing merchant promotional code distributed through Shopbonus.
    case PromotionalCode = 'promotional_code';
}
