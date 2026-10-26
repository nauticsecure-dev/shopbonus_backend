<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property string $public_id
 * @property string $notification_id
 * @property string $channel
 * @property string|null $error_code
 * @property CarbonImmutable|null $sent_at
 * @property string $status
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $claimed_at
 * @property string|null $claim_token
 */
class NotificationDelivery extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'claim_token'];

    protected function casts(): array
    {
        return ['claimed_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
