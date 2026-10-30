<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A future publication (of one exact draft revision) or archiving of a CMS
 * page (Trello #33–#35 "scheduled publication").
 *
 * @property int $id
 * @property string $public_id
 * @property int $page_id
 * @property string $action
 * @property string|null $locale
 * @property int|null $draft_revision
 * @property array{legal_version?: string, effective_date?: string}|null $legal
 * @property CarbonImmutable $due_at
 * @property string $status
 * @property string|null $outcome_code
 * @property int|null $revision_id
 * @property int $scheduled_by
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $processed_at
 */
class CmsPublicationSchedule extends Model
{
    use HasUlids;

    public const ACTIONS = ['publish', 'archive'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'scheduled'];

    protected function casts(): array
    {
        return ['legal' => 'array', 'draft_revision' => 'integer', 'due_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
