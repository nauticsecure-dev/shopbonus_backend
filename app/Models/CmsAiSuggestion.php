<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A private, human-reviewed AI text suggestion for one CMS page locale
 * (Trello #33–#35 "Improve with AI"). It never publishes; applying it only
 * saves a draft.
 *
 * @property int $id
 * @property string $public_id
 * @property int $page_id
 * @property string $kind
 * @property string $source_locale
 * @property string $target_locale
 * @property int $source_revision
 * @property int|null $target_revision
 * @property string $status
 * @property array<string, mixed>|null $proposed
 * @property string|null $error_code
 * @property int $created_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 */
class CmsAiSuggestion extends Model
{
    use HasUlids;

    public const KINDS = ['improve', 'translate'];

    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'request_hash'];

    protected function casts(): array
    {
        return ['proposed' => 'array', 'source_revision' => 'integer', 'target_revision' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
