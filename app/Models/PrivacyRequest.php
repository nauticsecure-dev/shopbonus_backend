<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A consumer's data-rights request (Trello #10 §9). Recording it never
 * deletes anything by itself; processing follows retention rules (D-013).
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $type
 * @property string $status
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $completed_at
 */
class PrivacyRequest extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
