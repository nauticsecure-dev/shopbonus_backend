<?php

namespace App\Listeners;

use App\Events\OrderEventVerified;
use App\Events\RefundEventVerified;
use App\Services\Answer24\ContactPlanner;
use App\Services\Orders\OrderIngestion;
use App\Services\Vouchers\RedemptionMatcher;

/**
 * Runs inside ProcessWebhookEvent's transaction, so an order is stored if
 * and only if its event is marked processed.
 */
class IngestOrderEvents
{
    public function __construct(
        private readonly OrderIngestion $ingestion,
        private readonly RedemptionMatcher $redemptions,
        private readonly ContactPlanner $contacts,
    ) {}

    public function handleOrder(OrderEventVerified $event): void
    {
        $order = $this->ingestion->ingest($event->event, $event->connection, $event->order);

        if ($order !== null) {
            $this->redemptions->match($order, $event->order->couponCodes);
            // Answer24 eligibility is re-evaluated on every change (cancel/refund remove it again).
            $this->contacts->sync($order, $event->connection->webshop);
        }
    }

    public function handleRefund(RefundEventVerified $event): void
    {
        $order = $this->ingestion->refundNotified($event->event, $event->connection, $event->externalOrderId);

        if ($order !== null) {
            $this->contacts->sync($order, $event->connection->webshop);
        }
    }
}
