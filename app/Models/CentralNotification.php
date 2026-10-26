<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Laravel's standard database notification contract, with encrypted text.
 *
 * @property string $id
 * @property string $event_type
 * @property string $event_key
 * @property string $recipient_type
 * @property int|null $webshop_id
 * @property array<string, mixed> $data
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
class CentralNotification extends DatabaseNotification
{
    /** @return HasOne<NotificationDelivery, $this> */
    public function delivery(): HasOne
    {
        return $this->hasOne(NotificationDelivery::class, 'notification_id');
    }

    protected $hidden = ['notifiable_type', 'notifiable_id', 'event_key', 'webshop_id'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'read_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}
