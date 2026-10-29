<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One discovery pass of the deployed code at a Git SHA, with the diff against
 * the previous pass (Trello #37 "compare the inventory against the previous Git SHA").
 *
 * @property int $id
 * @property string $public_id
 * @property string $git_sha
 * @property array<string, int> $totals
 * @property array<string, mixed> $diff
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
class QaInventorySnapshot extends Model
{
    use HasUlids;

    protected $table = 'qa_inventory_snapshots';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['totals' => 'array', 'diff' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
