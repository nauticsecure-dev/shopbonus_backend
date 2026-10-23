<?php

namespace App\Enums;

/**
 * Unit of a wallet's integer amounts. Units are never converted into each
 * other implicitly (OPEN-REWARD-UNITS safe default).
 */
enum WalletUnit: string
{
    // Non-withdrawable ShopBonus credit in euro cents (card #23 "€4.75 credit").
    case EurCredit = 'eur_credit';
    // Virtual loyalty points (card #19).
    case Points = 'points';
}
