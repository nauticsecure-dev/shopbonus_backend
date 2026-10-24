<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable published revision of one page locale. Restoring creates a new
 * revision; legal evidence (version, effective date, approver) is kept.
 *
 * @property int $id
 * @property string $public_id
 * @property int $page_id
 * @property string $locale
 * @property int $number
 * @property array<string, mixed> $content
 * @property string|null $legal_version
 * @property CarbonImmutable|null $effective_date
 * @property int|null $approved_by
 * @property int $published_by
 * @property int|null $restored_from_id
 * @property CarbonImmutable $created_at
 */
class CmsRevision extends Model
{
    use AppendOnly, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'number' => 'integer', 'effective_date' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
