<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A banner campaign on the merchant's own site (Trello #25). The draft is
 * private; visitors only ever get an immutable published BannerVersion.
 * Status: draft | scheduled | live | paused.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property int|null $offer_id
 * @property string $name
 * @property string $status
 * @property array<string, mixed> $draft_config
 * @property int $draft_revision
 * @property int|null $published_version_id
 * @property CarbonImmutable|null $scheduled_at
 * @property int $created_by
 * @property CarbonImmutable $updated_at
 */
class BannerCampaign extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'offer_id', 'published_version_id', 'created_by'];

    protected function casts(): array
    {
        return ['draft_config' => 'array', 'draft_revision' => 'integer', 'scheduled_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
