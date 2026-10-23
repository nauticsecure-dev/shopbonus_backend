<?php

namespace App\Enums;

enum VoucherCodeStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Invalid = 'invalid';
    case Cancelled = 'cancelled';
}
