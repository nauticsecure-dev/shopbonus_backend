<?php

namespace App\Events;

use App\Integrations\Data\NormalizedOrder;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A verified provider event carried an order. Order ingestion (B05) listens
 * here; the normalised order is never trusted for payment without its
 * provider-reported status.
 */
class OrderEventVerified
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $event,
        public readonly WebshopConnection $connection,
        public readonly NormalizedOrder $order,
    ) {}
}
