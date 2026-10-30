<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A discovered /api/v1 route. Removed routes are kept (removed_at) and never
 * deleted; endpoints without frontend usage are only flagged for review.
 *
 * @property int $id
 * @property string $public_id
 * @property string $method
 * @property string $path
 * @property string|null $name
 * @property string|null $action
 * @property string|null $summary
 * @property string $access
 * @property string|null $permission
 * @property string $scope
 * @property bool $documented
 * @property string $fingerprint
 * @property string $first_seen_sha
 * @property string $changed_sha
 * @property CarbonImmutable $changed_at
 * @property CarbonImmutable|null $removed_at
 * @property bool|null $frontend_referenced
 */
class QaEndpoint extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['documented' => 'boolean', 'frontend_referenced' => 'boolean', 'changed_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
