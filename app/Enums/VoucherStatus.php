<?php

namespace App\Enums;

/**
 * Consumer voucher lifecycle. `Issued` means assigned to a verified
 * consumer; it is NOT proof of redemption (Trello: "issuance is not the
 * same as verified merchant-side redemption").
 */
enum VoucherStatus: string
{
    case Reserved = 'reserved';
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
