<?php

namespace App\Services\Orders;

use App\Events\OrderSheetUpdated;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Models\OrderEvent;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Banners\BannerExperiments;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns verified provider events into normalised orders (Trello #1
 * "OrderIngestionService"). Runs inside the event-processing transaction.
 *
 * - One order per (webshop, platform, external ID), upserted under a row lock.
 * - Out-of-order safety: an event older than the stored provider update
 *   time is recorded as `ignored_stale`; without comparable timestamps only
 *   forward payment transitions apply (PaymentTransitions).
 * - Values are never fabricated. A field missing from a later event (e.g.
 *   redacted) does not erase a value received earlier.
 */
class OrderIngestion
{
    public function ingest(WebhookEvent $event, WebshopConnection $connection, NormalizedOrder $incoming): ?WebshopOrder
    {
        if ($incoming->externalId === null) {
            $this->noteOnEvent($event, ['ingested' => false, 'reason' => 'missing_external_id']);

            return null;
        }

        $key = ['webshop_id' => $connection->webshop_id, 'platform' => $connection->platform->value, 'external_order_id' => $incoming->externalId];
        $order = WebshopOrder::where($key)->lockForUpdate()->first();

        if ($order === null) {
            try {
                // Savepoint: a concurrent first insert must not abort the outer transaction.
                $order = DB::transaction(fn () => $this->create($key, $connection, $event, $incoming));
                $this->noteOnEvent($event, ['ingested' => true, 'order_id' => $order->public_id, 'change' => 'created']);
                $this->broadcast($order);

                return $order;
            } catch (UniqueConstraintViolationException) {
                $order = WebshopOrder::where($key)->lockForUpdate()->firstOrFail();
            }
        }

        $change = $this->update($order, $event, $incoming);
        $this->noteOnEvent($event, ['ingested' => $change !== 'ignored_stale', 'order_id' => $order->public_id, 'change' => $change]);

        if ($change !== 'ignored_stale') {
            $this->broadcast($order);
        }

        return $order;
    }

    /**
     * A refund notification without amounts: a paid order becomes
     * `partially_refunded` until the provider's order update gives the
     * exact state. Conservative: it can only reduce eligibility.
     */
    public function refundNotified(WebhookEvent $event, WebshopConnection $connection, string $externalOrderId): ?WebshopOrder
    {
        $order = WebshopOrder::where([
            'webshop_id' => $connection->webshop_id, 'platform' => $connection->platform->value, 'external_order_id' => $externalOrderId,
        ])->lockForUpdate()->first();

        if ($order === null) {
            $this->noteOnEvent($event, ['ingested' => false, 'reason' => 'order_not_found']);

            return null;
        }

        $from = $order->payment_status;
        if ($from === PaymentStatus::Paid) {
            $order->forceFill(['payment_status' => PaymentStatus::PartiallyRefunded, 'refunded_at' => $order->refunded_at ?? now(), 'last_webhook_event_id' => $event->id])->save();
        }

        $this->history($order, $event, 'refund_notified', $from, $order->payment_status, []);
        $this->noteOnEvent($event, ['ingested' => true, 'order_id' => $order->public_id, 'change' => 'refund_notified']);
        $this->broadcast($order);

        return $order;
    }

    /**
     * @param  array<string, int|string>  $key
     */
    private function create(array $key, WebshopConnection $connection, WebhookEvent $event, NormalizedOrder $o): WebshopOrder
    {
        $order = new WebshopOrder($key + ['connection_id' => $connection->id]);
        $this->fill($order, $o, $event);
        $order->save();

        $this->history($order, $event, 'created', null, $order->payment_status, ['complete' => $order->ingestion_status === 'complete'], lineItems: $o->lineItems);

        return $order;
    }

    /** @return string created|updated|payment_changed|ignored_stale */
    private function update(WebshopOrder $order, WebhookEvent $event, NormalizedOrder $o): string
    {
        $from = $order->payment_status;
        $stored = $order->provider_updated_at;
        $incomingAt = $o->providerUpdatedAt;

        $stale = match (true) {
            $stored !== null && $incomingAt !== null && $incomingAt->lessThan($stored) => 'older_than_stored',
            ($stored === null || $incomingAt === null || $incomingAt->equalTo($stored)) && ! PaymentTransitions::isForward($from, $o->paymentStatus) => 'not_forward',
            default => null,
        };

        if ($stale !== null) {
            $this->history($order, $event, 'ignored_stale', $from, $o->paymentStatus, ['reason' => $stale], $incomingAt);

            return 'ignored_stale';
        }

        $this->fill($order, $o, $event);
        $order->save();

        $type = $order->payment_status !== $from ? 'payment_changed' : 'updated';
        $this->history($order, $event, $type, $from, $order->payment_status, ['complete' => $order->ingestion_status === 'complete'], $incomingAt, $o->lineItems);

        return $type;
    }

    private function fill(WebshopOrder $order, NormalizedOrder $o, WebhookEvent $event): void
    {
        $keep = fn ($new, $old) => $new ?? $old;

        $email = $keep($o->email, $order->customer_email);
        $phone = $keep($o->phone, $order->customer_phone);

        $order->forceFill([
            'reference' => $keep($o->reference, $order->reference),
            'customer_first_name' => $keep($o->firstName, $order->customer_first_name),
            'customer_email' => $email,
            'customer_email_hash' => WebshopOrder::emailHash($email),
            'customer_phone' => $phone,
            'has_phone' => $phone !== null && $phone !== '',
            'customer_key' => WebshopOrder::customerKey($order->webshop_id, $email, PhoneNumber::normalize($phone)),
            // Consent is only ever taken from the provider, never assumed; a later event without it keeps the earlier answer.
            'contact_consent' => $o->contactConsent ?? $order->contact_consent,
            'attribution_click_id' => $o->attributionClickId ?? $order->attribution_click_id,
            'banner_assignment_id' => $order->getAttribute('banner_assignment_id') ?? app(BannerExperiments::class)->orderAssignment($order->webshop_id, $o->bannerAssignmentToken),
            'amount_minor' => $keep($o->amountMinor, $order->amount_minor),
            'currency' => $keep($o->currency, $order->currency),
            'refunded_minor' => $o->refundedMinor ?? $order->refunded_minor ?? 0,
            'payment_status' => $o->paymentStatus,
            'provider_status' => $keep($o->orderStatus, $order->provider_status),
            'placed_at' => $keep($o->placedAt, $order->placed_at),
            'paid_at' => $keep($o->paidAt, $order->paid_at),
            'cancelled_at' => $keep($o->cancelledAt, $order->cancelled_at),
            'refunded_at' => $keep($o->refundedAt, $order->refunded_at),
            'provider_updated_at' => $keep($o->providerUpdatedAt, $order->provider_updated_at),
            'last_webhook_event_id' => $event->id,
            'line_items' => $o->lineItems ?? $order->line_items,
        ]);

        $missing = array_keys(array_filter([
            'reference' => $order->reference, 'first_name' => $order->customer_first_name, 'email' => $order->customer_email,
            'amount_minor' => $order->amount_minor, 'currency' => $order->currency, 'placed_at' => $order->placed_at,
        ], fn ($v) => $v === null || $v === ''));

        $order->forceFill([
            'ingestion_status' => $missing === [] ? 'complete' : 'incomplete',
            'missing_fields' => $missing === [] ? null : $missing,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string,mixed>>|null  $lineItems
     */
    private function history(WebshopOrder $order, WebhookEvent $event, string $type, ?PaymentStatus $from, ?PaymentStatus $to, array $summary, mixed $providerAt = null, ?array $lineItems = null): void
    {
        OrderEvent::create([
            'order_id' => $order->id,
            'webshop_id' => $order->webshop_id,
            'webhook_event_id' => $event->id,
            'type' => $type,
            'from_payment_status' => $from?->value,
            'to_payment_status' => $to?->value,
            'summary' => $summary + ['topic' => $event->topic],
            'line_items_snapshot' => $lineItems,
            'provider_updated_at' => $providerAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $note
     */
    private function noteOnEvent(WebhookEvent $event, array $note): void
    {
        $event->forceFill(['result' => [...($event->result ?? []), ...$note]])->save();
    }

    private function broadcast(WebshopOrder $order): void
    {
        DB::afterCommit(fn () => OrderSheetUpdated::dispatch($order->webshop_id, $order->public_id, $order->payment_status->value));
    }
}
