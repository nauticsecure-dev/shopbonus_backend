<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A notification for one workspace (Trello #8 §6). Deduplicated by
 * (webshop, dedupe_key); read state is shared by the workspace's members.
 *
 * @property int $id
 * @property string $public_id
 * @property int $webshop_id
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string $body
 * @property string|null $subject_type
 * @property string|null $subject_ref
 * @property string $dedupe_key
 * @property CarbonImmutable|null $read_at
 * @property int|null $read_by
 * @property CarbonImmutable $created_at
 */
class MerchantNotification extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'webshop_id', 'read_by', 'dedupe_key'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
