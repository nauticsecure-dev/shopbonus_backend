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
 * Shopify order webhooks signed with the merchant's webhook signing secret.
 *
 * Signature: `X-Shopify-Hmac-Sha256` = base64(HMAC-SHA256(raw body, secret)).
 * Dedupe: `X-Shopify-Webhook-Id`. The shop must match the configured
 * `*.myshopify.com` domain (`X-Shopify-Shop-Domain`).
 *
 * Shopify redacts customer fields unless the app has protected customer
 * data access; then the order is incomplete and the connection is marked
 * `limited` with `additional_integration_required` (Trello #1). Enable only
 * after a genuine paid order has been verified.
 *
 * NOT VERIFIED against a live store.
 */
class ShopifyConnector extends WebhookConnector
{
    public const ORDER_TOPICS = ['orders/create', 'orders/updated', 'orders/paid', 'orders/cancelled', 'orders/fulfilled'];

    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::Shopify;
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::Webhook;
    }

    /** @return array<string, mixed> */
    public function connectRules(): array
    {
        return [
            'shop_domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/'],
            'signing_secret' => ['required', 'string', 'min:16', 'max:255'],
        ];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        $connection->external_shop_ref = strtolower((string) $input['shop_domain']);

        return ['signing_secret' => (string) $input['signing_secret']];
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        return [
            'format' => 'shopify-webhooks',
            'webhook_url' => $webhookUrl,
            'shop_domain' => $connection->external_shop_ref,
            'steps' => [
                'In Shopify admin open Settings → Notifications → Webhooks and create webhooks (JSON) for "Order creation", "Order payment", "Order update" and "Order cancellation" to the webhook URL.',
                'Copy the signing secret shown on that page into this connection.',
                'Place a genuine paid test order. Shopify may hide customer details; if so the connection shows "Connected with limitations" until protected customer data access is arranged.',
            ],
        ];
    }

    public function verifyIncomingEvent(WebshopConnection $connection, Request $request): VerifiedEvent
    {
        $body = $this->rawBody($request);
        $this->assertBase64Hmac($body, $this->requireSecret($connection, 'signing_secret'), $request->header('X-Shopify-Hmac-Sha256'));

        $shop = strtolower((string) $request->header('X-Shopify-Shop-Domain', ''));
        if ($connection->external_shop_ref !== null && $shop !== $connection->external_shop_ref) {
            throw new InvalidWebhook('shop_mismatch');
        }

        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id', ''));
        $topic = (string) $request->header('X-Shopify-Topic', '');

        if ($webhookId === '' || strlen($webhookId) > 100 || $topic === '') {
            throw new InvalidWebhook('invalid_event_headers');
        }

        $kind = match (true) {
            in_array($topic, self::ORDER_TOPICS, true) => VerifiedEvent::KIND_ORDER,
            $topic === 'refunds/create' => VerifiedEvent::KIND_REFUND,
            default => VerifiedEvent::KIND_OTHER,
        };

        return new VerifiedEvent('webhook:'.$webhookId, $topic, $kind);
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $billing = is_array($payload['billing_address'] ?? null) ? $payload['billing_address'] : [];
        $currency = $this->str($payload['currency'] ?? null);
        $currency = Money::isCurrency($currency) ? $currency : null;

        $amount = null;
        if ($currency !== null) {
            try {
                $amount = Money::toMinor((string) ($payload['total_price'] ?? ''), $currency);
            } catch (InvalidArgumentException) {
                $amount = null;
            }
        }

        $financial = $this->str($payload['financial_status'] ?? null);
        $cancelledAt = $this->date($payload['cancelled_at'] ?? null);

        $payment = match (true) {
            $cancelledAt !== null, $financial === 'voided' => PaymentStatus::Cancelled,
            $financial === 'paid' => PaymentStatus::Paid,
            $financial === 'partially_refunded' => PaymentStatus::PartiallyRefunded,
            $financial === 'refunded' => PaymentStatus::Refunded,
            in_array($financial, ['pending', 'authorized', 'partially_paid'], true) => PaymentStatus::Pending,
            default => PaymentStatus::Unknown,
        };

        return new NormalizedOrder(
            externalId: $this->str($payload['id'] ?? null),
            reference: $this->str($payload['name'] ?? $payload['order_number'] ?? null),
            firstName: $this->str($customer['first_name'] ?? $billing['first_name'] ?? null),
            email: $this->str($payload['email'] ?? $customer['email'] ?? null),
            phone: $this->str($payload['phone'] ?? $customer['phone'] ?? $billing['phone'] ?? null),
            amountMinor: $amount !== null && $amount >= 0 ? $amount : null,
            currency: $currency,
            paymentStatus: $payment,
            orderStatus: $financial,
            placedAt: $this->date($payload['created_at'] ?? null),
            paidAt: $payment === PaymentStatus::Paid ? $this->date($payload['processed_at'] ?? null) : null,
            cancelledAt: $cancelledAt,
            refundedAt: in_array($payment, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true) ? $this->date($payload['updated_at'] ?? null) : null,
        );
    }
}
