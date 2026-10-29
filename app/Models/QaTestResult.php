<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One test outcome in a run. PASS requires at least one executed assertion;
 * mocked results are labelled and never count as provider proof.
 *
 * @property int $id
 * @property string $public_id
 * @property int $run_id
 * @property int|null $test_case_id
 * @property string $key
 * @property string $status
 * @property int $assertions
 * @property bool $mocked
 * @property int $duration_ms
 * @property string|null $message
 * @property CarbonImmutable|null $created_at
 */
class QaTestResult extends Model
{
    use HasUlids;

    public const STATUSES = ['pass', 'fail', 'blocked', 'not_implemented', 'skipped'];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['assertions' => 'integer', 'mocked' => 'boolean', 'duration_ms' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
