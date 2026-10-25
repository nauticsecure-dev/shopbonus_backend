<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $incident_id
 * @property int $requested_by
 * @property string $request_key
 * @property string $status
 * @property array<string, mixed> $context
 * @property string $context_hash
 * @property string $model
 * @property string|null $failure_code
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
class IncidentDiagnostic extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run) {
            foreach (['public_id', 'incident_id', 'requested_by', 'request_key', 'context', 'context_hash', 'model'] as $field) {
                if ($run->isDirty($field)) {
                    throw new \LogicException('Diagnostic evidence is immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Diagnostic history must be preserved.'));
    }
}
