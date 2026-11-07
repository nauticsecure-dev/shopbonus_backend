<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property string $public_id
 * @property int $created_by
 * @property string $request_key
 * @property string $deployment_sha
 * @property string $trigger
 * @property string $status
 * @property string|null $failure_code
 * @property bool $evidence_complete
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 */
class QaExecutionRequest extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued', 'evidence_complete' => false];

    protected function casts(): array
    {
        return ['evidence_complete' => 'boolean', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
