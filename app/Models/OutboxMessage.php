<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Transactional outbox entry: written in the same transaction as the state
 * change it announces; delivered later by the dispatcher (B07b). The unique
 * idempotency key prevents duplicate messages for the same fact.
 *
 * @property int $id
 * @property string $public_id
 * @property string $target
 * @property string $type
 * @property int|null $webshop_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed> $payload
 * @property string $idempotency_key
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $sent_at
 * @property string|null $last_error
 */
class OutboxMessage extends Model
{
    use HasUlids;

    protected $table = 'integration_outbox';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'next_attempt_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
