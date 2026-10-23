<?php

namespace App\Events;

use App\Models\Webshop;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live Order Sheet signal (Trello #1). Carries no PII: clients refetch the
 * order through the authorised API. Private channel per webshop, authorised
 * in routes/channels.php (active member with orders.view).
 */
class OrderSheetUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $webshopId,
        public readonly string $orderId,
        public readonly string $paymentStatus,
    ) {}

    public static function channelName(string $webshopPublicId): string
    {
        return "webshops.{$webshopPublicId}.orders";
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $publicId = Webshop::whereKey($this->webshopId)->value('public_id');

        return $publicId === null ? [] : [new PrivateChannel(self::channelName((string) $publicId))];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'payment_status' => $this->paymentStatus];
    }
}
