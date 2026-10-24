<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable published banner configuration.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int $version
 * @property array<string, mixed> $config
 * @property int|null $published_by
 * @property CarbonImmutable $created_at
 */
class BannerVersion extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['config' => 'array', 'version' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
