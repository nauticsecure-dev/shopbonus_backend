<?php

namespace App\Events;

use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A verified refund notification for an existing order (e.g. Shopify
 * `refunds/create`). The exact refunded amount follows in an order update.
 */
class RefundEventVerified
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $event,
        public readonly WebshopConnection $connection,
        public readonly string $externalOrderId,
    ) {}
}
