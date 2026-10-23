<?php

namespace App\Integrations\Connectors;

use App\Enums\ConnectionMethod;
use App\Enums\EcommercePlatform;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\WebshopConnection;
use App\Support\Money;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * WooCommerce native webhooks (WooCommerce → Settings → Advanced → Webhooks).
 *
 * Signature: `X-WC-Webhook-Signature` = base64(HMAC-SHA256(raw body, secret)),
 * with the secret Shopbonus generates and the merchant pastes into Woo.
 * Dedupe: `X-WC-Webhook-Delivery-ID`. Order bodies follow the REST API v3
 * order schema.
 *
 * NOT VERIFIED against a live store (no test store available).
 */
class WooCommerceConnector extends WebhookConnector
{
    public const ORDER_TOPICS = ['order.created', 'order.updated', 'order.restored'];

    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::WooCommerce;
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::Webhook;
    }

    /** @return array<string, mixed> */
    public function connectRules(): array
    {
        return ['store_url' => ['sometimes', 'nullable', 'url:https', 'max:255']];
    }

    /** @return list<string> */
    public function generatedSecrets(): array
    {
        return ['webhook_secret'];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        return ['webhook_secret' => $this->newSecret()];
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        return [
            'format' => 'woocommerce-webhooks',
            'webhook_url' => $webhookUrl,
            'steps' => [
                'In WordPress go to WooCommerce → Settings → Advanced → Webhooks → Add webhook.',
                'Create two webhooks, topics "Order created" and "Order updated", status Active, API version WP REST API Integration v3.',
                'Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.',
                'Save, then place a real paid test order.',
            ],
        ];
    }

    public function verifyIncomingEvent(WebshopConnection $connection, Request $request): VerifiedEvent
    {
        $body = $this->rawBody($request);
        $this->assertBase64Hmac($body, $this->requireSecret($connection, 'webhook_secret'), $request->header('X-WC-Webhook-Signature'));

        $topic = $request->header('X-WC-Webhook-Topic');
        $delivery = trim((string) $request->header('X-WC-Webhook-Delivery-ID', ''));

        // Woo sends a signed ping (`webhook_id=<id>`) when a webhook is saved.
        if ($topic === null && preg_match('/^webhook_id=\d+$/', trim($body)) === 1) {
            return new VerifiedEvent('ping:'.hash('sha256', $body), null, VerifiedEvent::KIND_PING);
        }

        if ($delivery === '' || strlen($delivery) > 100) {
            throw new InvalidWebhook('invalid_event_headers');
        }

        $kind = in_array($topic, self::ORDER_TOPICS, true) ? VerifiedEvent::KIND_ORDER : VerifiedEvent::KIND_OTHER;

        return new VerifiedEvent('delivery:'.$delivery, is_string($topic) ? $topic : null, $kind);
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $currency = $this->str($payload['currency'] ?? null);
        $currency = Money::isCurrency($currency) ? $currency : null;
        $status = $this->str($payload['status'] ?? null);
        $paidAt = $this->date($payload['date_paid_gmt'] ?? null);
        $refunds = is_array($payload['refunds'] ?? null) ? $payload['refunds'] : [];

        $amount = null;
        $refunded = 0;
        if ($currency !== null) {
            try {
                $amount = Money::toMinor((string) ($payload['total'] ?? ''), $currency);
                foreach ($refunds as $refund) {
                    $refunded += abs(Money::toMinor((string) ($refund['total'] ?? '0'), $currency));
                }
            } catch (InvalidArgumentException) {
                $amount = null;
            }
        }

        // Woo's status is the merchant store's own order state; "processing" and
        // "completed" mean paid, but only with a payment date as evidence.
        $payment = match (true) {
            $status === 'refunded' => PaymentStatus::Refunded,
            $status === 'cancelled' => PaymentStatus::Cancelled,
            $status === 'failed' => PaymentStatus::Failed,
            in_array($status, ['processing', 'completed'], true) && $paidAt !== null => $refunded > 0 ? PaymentStatus::PartiallyRefunded : PaymentStatus::Paid,
            in_array($status, ['pending', 'on-hold', 'checkout-draft', 'processing', 'completed'], true) => PaymentStatus::Pending,
            default => PaymentStatus::Unknown,
        };

        return new NormalizedOrder(
            externalId: $this->str($payload['id'] ?? null),
            reference: $this->str($payload['number'] ?? $payload['id'] ?? null),
            firstName: $this->str($billing['first_name'] ?? null),
            email: $this->str($billing['email'] ?? null),
            phone: $this->str($billing['phone'] ?? null),
            amountMinor: $amount !== null && $amount >= 0 ? $amount : null,
            currency: $currency,
            paymentStatus: $payment,
            orderStatus: $status,
            placedAt: $this->date($payload['date_created_gmt'] ?? null),
            paidAt: $paidAt,
            cancelledAt: $status === 'cancelled' ? $this->date($payload['date_modified_gmt'] ?? null) : null,
            refundedAt: $refunded > 0 ? $this->date($payload['date_modified_gmt'] ?? null) : null,
            refundedMinor: $refunded > 0 ? $refunded : null,
            providerUpdatedAt: $this->date($payload['date_modified_gmt'] ?? null),
        );
    }
}
