<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imported execution (JUnit report or production probe).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $schedule_id
 * @property string $suite
 * @property string $environment
 * @property string $git_sha
 * @property string $source
 * @property string $report_hash
 * @property array<string, int> $totals
 * @property int $duration_ms
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 */
class QaTestRun extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['totals' => 'array', 'duration_ms' => 'integer', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<QaEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(QaEvidence::class, 'run_id');
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
