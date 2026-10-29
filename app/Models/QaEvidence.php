<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A private, encrypted artifact (screenshot, HTTP transcript, report) for a
 * run, identified by its checksum.
 *
 * @property int $id
 * @property string $public_id
 * @property int $run_id
 * @property int|null $result_id
 * @property string $path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string|null $request_id
 * @property CarbonImmutable $retain_until
 * @property int $uploaded_by
 */
class QaEvidence extends Model
{
    use HasUlids;

    protected $table = 'qa_evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'retain_until' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
