<?php

namespace App\Enums;

enum WalletKind: string
{
    // A consumer's central, non-cash wallet (one per unit).
    case ConsumerCredit = 'consumer_credit';
    // A merchant's funding balance for sponsored vouchers (B12).
    case MerchantFunding = 'merchant_funding';
}
