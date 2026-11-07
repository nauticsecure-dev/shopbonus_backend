<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** One-time nonce to the requesting admin's own private channel; the browser echoes it back. */
final class SupportBrowserProbe implements ShouldBroadcastNow
{
    public function __construct(private readonly string $userPublicId, private readonly string $nonce) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('shopbonus.user.'.$this->userPublicId.'.notifications')];
    }

    public function broadcastAs(): string
    {
        return 'support.connectivity.browser_probe';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['nonce' => $this->nonce];
    }
}
