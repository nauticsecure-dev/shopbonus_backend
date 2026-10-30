<?php

namespace App\Services\Attribution;

use App\Models\PromotionEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Append-only backend funnel events (Trello #24 §1). Idempotent on event_id.
 */
class PromotionEvents
{
    public const TYPES = [
        'claim_started', 'account_verified',
        'voucher_reserved', 'voucher_issued', 'voucher_redeemed',
        'order_paid', 'order_refunded', 'repeat_order',
    ];

    /**
     * @param  array<string, mixed>  $context  No PII or payment secrets.
     */
    public function record(
        int $webshopId,
        string $type,
        string $source,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $context = [],
        ?string $eventId = null,
    ): bool {
        if (! in_array($type, self::TYPES, true)) {
            return false;
        }

        try {
            PromotionEvent::query()->create([
                'event_id' => $eventId ?? (string) Str::uuid(),
                'webshop_id' => $webshopId,
                'type' => $type,
                'source' => $source,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'context' => $context === [] ? null : $context,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
