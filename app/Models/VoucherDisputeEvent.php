<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only timeline of a dispute.
 *
 * @property int $id
 * @property int $dispute_id
 * @property string $actor_role
 * @property int|null $actor_user_id
 * @property string $type
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
class VoucherDisputeEvent extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
