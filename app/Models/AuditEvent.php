<?php

namespace App\Models;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit record. Write through App\Services\Audit\AuditLogger.
 *
 * Updates are refused here and by a database trigger. Deletes are refused
 * here; a future retention job must use an explicit, audited path.
 *
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property string|null $request_id
 * @property AuditCategory $category
 * @property string $action
 * @property AuditOutcome $outcome
 * @property int|null $actor_user_id
 * @property int|null $real_actor_user_id
 * @property int|null $webshop_id
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property int|null $http_status
 * @property string|null $http_method
 * @property string|null $route
 * @property string|null $ip_hash
 * @property array<string, mixed>|null $metadata
 */
class AuditEvent extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'category' => AuditCategory::class,
            'outcome' => AuditOutcome::class,
            'http_status' => 'integer',
            'metadata' => 'array',
        ];
    }
}
