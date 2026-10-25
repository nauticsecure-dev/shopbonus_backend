<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property string $fingerprint
 * @property string $category
 * @property string $severity
 * @property string $service
 * @property string|null $route
 * @property string|null $http_method
 * @property int|null $http_status
 * @property string $status
 * @property int|null $assigned_to
 * @property int $occurrences
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
class SecurityIncident extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['http_status' => 'integer', 'occurrences' => 'integer', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
