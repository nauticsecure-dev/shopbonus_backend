<?php

namespace App\Services\Orders;

use App\Integrations\Data\PaymentStatus;

/**
 * Which payment-status changes may be applied when event ordering is
 * unknown (no provider timestamp to compare). Only "forward" moves are
 * allowed, so a late or replayed event can never turn a refunded or
 * cancelled order back into a paid one.
 */
final class PaymentTransitions
{
    /** @var array<string, list<string>> */
    private const FORWARD = [
        'unknown' => ['pending', 'paid', 'failed', 'cancelled', 'refunded', 'partially_refunded'],
        'pending' => ['paid', 'failed', 'cancelled'],
        'failed' => ['pending', 'paid', 'cancelled'],
        'paid' => ['partially_refunded', 'refunded', 'cancelled'],
        'partially_refunded' => ['refunded', 'cancelled'],
        'cancelled' => [],
        'refunded' => [],
    ];

    public static function isForward(PaymentStatus $from, PaymentStatus $to): bool
    {
        return $from === $to || in_array($to->value, self::FORWARD[$from->value], true);
    }
}
