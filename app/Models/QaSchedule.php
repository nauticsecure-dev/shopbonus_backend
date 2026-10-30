<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * When a suite runs and against which environment. Production schedules can
 * only use the read-only safe suite.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $suite
 * @property string $environment
 * @property string $trigger
 * @property int $timeout_minutes
 * @property bool $active
 * @property CarbonImmutable|null $last_run_at
 * @property int $created_by
 */
class QaSchedule extends Model
{
    use HasUlids;

    public const SUITES = ['safe', 'changed', 'full', 'weekly'];

    public const ENVIRONMENTS = ['production', 'staging'];

    public const TRIGGERS = ['every_5_minutes', 'after_deploy', 'nightly', 'weekly'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['timeout_minutes' => 'integer', 'active' => 'boolean', 'last_run_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
