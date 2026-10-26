<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $conversation_id
 * @property int $user_id
 * @property string $request_key
 * @property string $request_hash
 * @property string $question
 * @property array<string, mixed> $research
 * @property array<string, mixed>|null $evidence
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $metrics
 * @property string $status
 * @property string|null $failure_code
 * @property bool $retry_used
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 */
class SupportMessage extends Model
{
    use HasUlids;

    protected $table = 'support_messages';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id', 'webshop_id', 'conversation_id', 'message_id', 'request_key', 'request_hash'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['question' => 'encrypted', 'research' => 'encrypted:array', 'evidence' => 'encrypted:array', 'result' => 'encrypted:array', 'metrics' => 'array', 'retry_used' => 'boolean', 'completed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $message) {
            foreach (['public_id', 'conversation_id', 'user_id', 'request_key', 'request_hash', 'question', 'research'] as $field) {
                if ($message->isDirty($field)) {
                    throw new \LogicException('Support request evidence is immutable.');
                }
            }
            if ($message->getOriginal('completed_at') !== null && $message->isDirty()) {
                throw new \LogicException('Completed support answers are immutable.');
            }
        });
    }
}
