<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class SupportProgress implements ShouldBroadcastNow
{
    public function __construct(private readonly string $conversationId, private readonly string $messageId, private readonly string $status, private readonly string $event) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('shopbonus.support.conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return $this->event;
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'message_id' => $this->messageId, 'status' => $this->status];
    }
}
