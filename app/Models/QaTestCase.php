<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A test known to the Quality Center. Executable code stays in Git; only
 * approved cases count towards coverage.
 *
 * @property int $id
 * @property string $public_id
 * @property string $key
 * @property string $title
 * @property string|null $scenario
 * @property string $source
 * @property string $status
 * @property int $version
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 */
class QaTestCase extends Model
{
    use HasUlids;

    public const SOURCES = ['phpunit', 'postman', 'browser', 'manual', 'ai'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'approved_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
