<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $faq_id
 * @property int $revision
 * @property array<string, mixed> $snapshot
 * @property string $checksum
 * @property int|null $recorded_by
 * @property string $origin
 * @property CarbonImmutable $created_at
 */
class SupportKnowledgeVersion extends Model
{
    use AppendOnly, HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'faq_id', 'recorded_by'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
