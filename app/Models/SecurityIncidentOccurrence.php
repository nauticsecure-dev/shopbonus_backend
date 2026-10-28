<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $incident_id
 * @property string|null $request_id
 * @property int|null $actor_user_id
 * @property int|null $webshop_id
 * @property string|null $scope_public_id
 * @property string|null $error_code
 * @property array<string, mixed>|null $monitoring_evidence
 * @property CarbonImmutable $occurred_at
 */
class SecurityIncidentOccurrence extends Model
{
    use AppendOnly, HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['monitoring_evidence' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
