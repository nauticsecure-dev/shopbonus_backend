<?php

namespace App\Services\Answer24;

use App\Models\OutboxMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Transactional outbox writer: call inside the transaction of the state
 * change. A repeated fact (same idempotency key) is ignored, so replays never
 * produce a second message.
 */
class Outbox
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $type, string $idempotencyKey, array $payload, ?int $webshopId = null, ?string $subjectType = null, ?int $subjectId = null): ?OutboxMessage
    {
        try {
            return DB::transaction(fn () => OutboxMessage::create([
                'target' => 'answer24', 'type' => $type, 'webshop_id' => $webshopId, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'payload' => $payload, 'idempotency_key' => $idempotencyKey, 'status' => 'pending', 'next_attempt_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function cancelPending(string $subjectType, int $subjectId, string $type): int
    {
        return OutboxMessage::where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('type', $type)
            ->where('status', 'pending')->update(['status' => 'cancelled', 'updated_at' => now()]);
    }
}
