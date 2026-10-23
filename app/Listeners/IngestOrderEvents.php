<?php

namespace App\Listeners;

use App\Events\OrderEventVerified;
use App\Events\RefundEventVerified;
use App\Services\Orders\OrderIngestion;

/**
 * Runs inside ProcessWebhookEvent's transaction, so an order is stored if
 * and only if its event is marked processed.
 */
class IngestOrderEvents
{
    public function __construct(private readonly OrderIngestion $ingestion) {}

    public function handleOrder(OrderEventVerified $event): void
    {
        $this->ingestion->ingest($event->event, $event->connection, $event->order);
    }

    public function handleRefund(RefundEventVerified $event): void
    {
        $this->ingestion->refundNotified($event->event, $event->connection, $event->externalOrderId);
    }
}
