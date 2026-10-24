<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One locale of a page: its slug, private draft (optimistic revision) and
 * the published immutable revision.
 *
 * @property int $id
 * @property int $page_id
 * @property string $locale
 * @property string $slug
 * @property array<string, mixed>|null $draft_content
 * @property int $draft_revision
 * @property CarbonImmutable|null $draft_updated_at
 * @property int|null $draft_updated_by
 * @property int|null $published_revision_id
 */
class CmsPageLocale extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['draft_revision' => 1];

    protected function casts(): array
    {
        return ['draft_content' => 'array', 'draft_revision' => 'integer', 'draft_updated_at' => 'immutable_datetime'];
    }
}
