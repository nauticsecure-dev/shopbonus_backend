<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A discovered non-HTTP function: job, event, listener, console command,
 * scheduled task, migration or provider connector.
 *
 * @property int $id
 * @property string $public_id
 * @property string $kind
 * @property string $name
 * @property string $fingerprint
 * @property string $first_seen_sha
 * @property string $changed_sha
 * @property CarbonImmutable $changed_at
 * @property CarbonImmutable|null $removed_at
 */
class QaComponent extends Model
{
    use HasUlids;

    public const KINDS = ['job', 'event', 'listener', 'command', 'schedule', 'migration', 'connector', 'middleware', 'policy', 'service', 'model', 'controller', 'request', 'resource', 'rule', 'enum', 'provider', 'support', 'logging', 'integration'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
