<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Queued invalidation only. Clients obtain all content/counts through authenticated HTTP. */
final class NotificationInboxChanged implements ShouldBroadcast
{
    public function __construct(private readonly string $userId, private readonly string $event, private readonly ?string $notificationId = null) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('shopbonus.user.'.$this->userId.'.notifications')];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return ['notification_id' => $this->notificationId];
    }
}
