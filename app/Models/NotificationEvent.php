<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property int $id
 * @property string $public_id
 * @property string $event_key
 * @property string $event_type
 * @property string $recipient_type
 * @property int|null $webshop_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 */
class NotificationEvent extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'payload', 'webshop_id', 'event_key'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'available_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
