<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Event-only one-time challenge plus public correlation ID on the requesting admin private channel. */
final class SupportBrowserProbe implements ShouldBroadcastNow
{
    public function __construct(private readonly string $userPublicId, private readonly string $probeId, private readonly string $nonce) {}

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
        return ['probe_id' => $this->probeId, 'nonce' => $this->nonce];
    }
}
