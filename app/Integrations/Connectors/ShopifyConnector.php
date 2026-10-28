<?php

namespace App\Integrations\Connectors;

use App\Enums\ConnectionMethod;
use App\Enums\EcommercePlatform;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Integrations\Http\ShopifyApi;
use App\Models\WebshopConnection;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
            'access_token' => ['nullable', 'string', 'min:16', 'max:2048', 'regex:/\A[A-Za-z0-9_-]+\z/D'],
        ];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        $connection->external_shop_ref = strtolower((string) $input['shop_domain']);

        return ['signing_secret' => (string) $input['signing_secret']] + (! empty($input['access_token']) ? ['access_token' => $input['access_token']] : []);
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        return [
            'format' => 'shopify-webhooks',
            'webhook_url' => $webhookUrl,
            'shop_domain' => $connection->external_shop_ref,
            'read_api_configured' => $connection->credential('access_token') !== null,
            'read_api_notice' => 'Optional approved app access token enables pinned GraphQL reads. OAuth install/refresh and live readiness are separate capabilities.',
            'steps' => [
                'In Shopify admin open Settings → Notifications → Webhooks and create webhooks (JSON) for "Order creation", "Order payment", "Order update" and "Order cancellation" to the webhook URL.',
                'Copy the signing secret shown on that page into this connection.',
                'Place a genuine paid test order. Shopify may hide customer details; if so the connection shows "Connected with limitations" until protected customer data access is arranged.',
            ],
        ];
    }

    public function testConnection(WebshopConnection $connection): array
    {
        return app(ShopifyApi::class)->test($connection);
    }

    public function fetchOrder(WebshopConnection $connection, string $externalId): array
    {
        return app(ShopifyApi::class)->order($connection, $externalId);
    }

    public function syncOrders(WebshopConnection $connection, CarbonImmutable $since): iterable
    {
        yield from app(ShopifyApi::class)->orders($connection, $since);
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
        if (array_key_exists('displayFinancialStatus', $payload)) {
            return $this->normalizeGraphql($payload);
        }
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
            couponCodes: array_values(array_filter(array_map(fn ($l) => is_array($l) ? $this->str($l['code'] ?? null) : null, is_array($payload['discount_codes'] ?? null) ? $payload['discount_codes'] : []))),
            // NOT VERIFIED: the storefront embed is expected to add a cart attribute "shopbonus_click_id".
            attributionClickId: GenericSignedWebhookConnector::clickId(collect(is_array($payload['note_attributes'] ?? null) ? $payload['note_attributes'] : [])
                ->first(fn ($a) => is_array($a) && ($a['name'] ?? null) === 'shopbonus_click_id')['value'] ?? null),
            bannerAssignmentToken: GenericSignedWebhookConnector::assignmentToken(collect(is_array($payload['note_attributes'] ?? null) ? $payload['note_attributes'] : [])
                ->first(fn ($m) => is_array($m) && ($m['name'] ?? null) === 'shopbonus_assignment_token')['value'] ?? null),
            providerUpdatedAt: $this->date($payload['updated_at'] ?? null),
        );
    }

    /** @param array<string,mixed> $payload */
    private function normalizeGraphql(array $payload): NormalizedOrder
    {
        $currency = $payload['totalPriceSet']['shopMoney']['currencyCode'] ?? null;
        $gross = $payload['totalPriceSet']['shopMoney']['amount'] ?? null;
        $refund = $payload['totalRefundedSet']['shopMoney']['amount'] ?? null;
        $amount = $refunded = null;
        if (Money::isCurrency($currency) && is_string($gross) && is_string($refund)) {
            try {
                $amount = Money::toMinor($gross, $currency);
                $refunded = Money::toMinor($refund, $currency);
            } catch (InvalidArgumentException) {
                $amount = $refunded = null;
            }
        }
        $status = $this->str($payload['displayFinancialStatus'] ?? null);
        $cancelled = $this->date($payload['cancelledAt'] ?? null);
        $payment = match (true) {
            $cancelled !== null, $status === 'VOIDED' => PaymentStatus::Cancelled,
            $status === 'REFUNDED' => PaymentStatus::Refunded,
            $status === 'PARTIALLY_REFUNDED' => PaymentStatus::PartiallyRefunded,
            $status === 'PAID' && ($payload['fullyPaid'] ?? false) === true => ($refunded ?? 0) > 0 ? PaymentStatus::PartiallyRefunded : PaymentStatus::Paid,
            in_array($status, ['PENDING', 'AUTHORIZED', 'PARTIALLY_PAID', 'PAID'], true) => PaymentStatus::Pending,
            default => PaymentStatus::Unknown,
        };
        $paidAt = $refundedAt = null;
        foreach (is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [] as $tx) {
            if (! is_array($tx) || ($tx['status'] ?? null) !== 'SUCCESS') {
                continue;
            }
            $at = $this->date($tx['processedAt'] ?? null);
            if (in_array($tx['kind'] ?? null, ['SALE', 'CAPTURE'], true) && $at !== null && ($paidAt === null || $at->greaterThan($paidAt))) {
                $paidAt = $at;
            }
            if (($tx['kind'] ?? null) === 'REFUND' && $at !== null && ($refundedAt === null || $at->greaterThan($refundedAt))) {
                $refundedAt = $at;
            }
        }
        $attributes = is_array($payload['customAttributes'] ?? null) ? $payload['customAttributes'] : [];

        return new NormalizedOrder(
            externalId: $this->str($payload['legacyResourceId'] ?? null), reference: $this->str($payload['name'] ?? null),
            firstName: $this->str($payload['billingAddress']['firstName'] ?? null), email: $this->str($payload['email'] ?? null),
            phone: $this->str($payload['phone'] ?? $payload['billingAddress']['phone'] ?? null),
            amountMinor: $amount !== null && $amount >= 0 ? $amount : null, currency: Money::isCurrency($currency) ? $currency : null,
            paymentStatus: $payment, orderStatus: $status, placedAt: $this->date($payload['createdAt'] ?? null),
            paidAt: $paidAt, cancelledAt: $cancelled, refundedAt: $refundedAt,
            refundedMinor: $refunded, providerUpdatedAt: $this->date($payload['updatedAt'] ?? null),
            couponCodes: array_values(array_filter(is_array($payload['discountCodes'] ?? null) ? $payload['discountCodes'] : [], fn ($value) => is_string($value) && strlen($value) <= 100)),
            attributionClickId: GenericSignedWebhookConnector::clickId(collect($attributes)->first(fn ($a) => is_array($a) && ($a['key'] ?? null) === 'shopbonus_click_id')['value'] ?? null),
            bannerAssignmentToken: GenericSignedWebhookConnector::assignmentToken(collect($attributes)->first(fn ($a) => is_array($a) && ($a['key'] ?? null) === 'shopbonus_assignment_token')['value'] ?? null),
        );
    }

    /**
     * `refunds/create` carries a refund, not an order: identify the order it belongs to.
     *
     * @param  array<string, mixed>  $payload
     */
    public function refundTarget(array $payload): ?string
    {
        return $this->str($payload['order_id'] ?? null);
    }
}
