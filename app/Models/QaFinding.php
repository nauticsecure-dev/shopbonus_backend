<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A reviewable defect. Status moves open → fixed (developer, with commit) →
 * retest_passed/retest_failed (a different QA user, with a run). AI analysis is
 * advisory only and never changes the status.
 *
 * @property int $id
 * @property string $public_id
 * @property string $fingerprint
 * @property string $title
 * @property string $severity
 * @property string $status
 * @property string|null $reproduction
 * @property list<string> $affected_routes
 * @property list<string> $test_keys
 * @property int|null $run_id
 * @property int|null $owner_id
 * @property string|null $fix_commit
 * @property int|null $fixed_by
 * @property CarbonImmutable|null $fixed_at
 * @property int|null $retest_run_id
 * @property int|null $retested_by
 * @property array<string, mixed>|null $ai_analysis
 * @property string|null $ai_status
 * @property int $created_by
 */
class QaFinding extends Model
{
    use HasUlids;

    public const SEVERITIES = ['p0', 'p1', 'p2', 'p3'];

    public const STATUSES = ['open', 'fixed', 'retest_passed', 'retest_failed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['affected_routes' => 'array', 'test_keys' => 'array', 'ai_analysis' => 'array', 'fixed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
