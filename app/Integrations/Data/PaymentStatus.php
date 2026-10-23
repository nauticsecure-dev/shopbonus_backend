<?php

namespace App\Integrations\Data;

/**
 * Payment state as reported by the provider. Only `Paid` can ever make an
 * order eligible for rewards or contact (B05/B07).
 */
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Unknown = 'unknown';
}
