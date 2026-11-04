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
 * @property string|null $source_hash
 * @property string|null $model
 * @property int|null $input_tokens
 * @property array<string,mixed>|null $context
 * @property string|null $budget_day
 * @property int|null $reserved_micros
 * @property int|null $estimated_micros
 * @property array<string,mixed>|null $pricing_snapshot
 * @property int|null $output_tokens
 */
class CmsAiSuggestion extends Model
{
    use HasUlids;

    public const KINDS = ['improve', 'translate'];

    protected $guarded = ['id'];

    protected $hidden = ['request_key', 'request_hash'];

    protected function casts(): array
    {
        return ['proposed' => 'array', 'source_revision' => 'integer', 'target_revision' => 'integer', 'decided_at' => 'immutable_datetime',
            'input_tokens' => 'integer', 'output_tokens' => 'integer', 'context' => 'array', 'pricing_snapshot' => 'array', 'reserved_micros' => 'integer', 'estimated_micros' => 'integer'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
