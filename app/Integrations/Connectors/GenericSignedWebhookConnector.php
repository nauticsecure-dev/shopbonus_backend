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

/**
 * Shopbonus' own signed webhook format, for private modules (PrestaShop,
 * OpenCart, Shopware 6, Magento) and custom shops. Fully specified in
 * docs/api/README.md → "Shopbonus webhook format v1".
 *
 *   X-Shopbonus-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>
 *   X-Shopbonus-Event-Id:  unique per event (dedupe)
 *   X-Shopbonus-Topic:     order.created | order.updated | order.paid | order.cancelled | order.refunded | ping
 *
 * Amounts are integer minor units in the body, so no decimal parsing.
 */
class GenericSignedWebhookConnector extends WebhookConnector
{
    public const TOLERANCE_SECONDS = 300;

    public const TOPICS = ['order.created', 'order.updated', 'order.paid', 'order.cancelled', 'order.refunded', 'ping'];

    public function __construct(private readonly EcommercePlatform $platform = EcommercePlatform::Other) {}

    public function platform(): EcommercePlatform
    {
        return $this->platform;
    }

    public function method(): ConnectionMethod
    {
        return $this->platform === EcommercePlatform::Other ? ConnectionMethod::Webhook : ConnectionMethod::PrivateModule;
    }

    /** @return array<string, mixed> */
    public function connectRules(): array
    {
        return [];
    }

    /** @return list<string> */
    public function generatedSecrets(): array
    {
        return ['signing_secret'];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        return ['signing_secret' => $this->newSecret()];
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        return [
            'format' => 'shopbonus-webhook-v1',
            'webhook_url' => $webhookUrl,
            'steps' => [
                $this->method() === ConnectionMethod::PrivateModule
                    ? 'A compatible merchant-side module is required. This backend does not include an installable module.'
                    : 'Configure your shop software to POST order events in the Shopbonus webhook format v1.',
                'Enter the webhook URL and the signing secret shown once when the connection was created (or after rotating it).',
                'Place a real test order. The connection remains Limited until all seven provider verification checks pass.',
            ],
            'documentation' => 'docs/api/README.md#shopbonus-webhook-format-v1',
        ];
    }

    public function verifyIncomingEvent(WebshopConnection $connection, Request $request): VerifiedEvent
    {
        $body = $this->rawBody($request);
        $secret = $this->requireSecret($connection, 'signing_secret');
        $header = (string) $request->header('X-Shopbonus-Signature', '');

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$k][] = $v;
        }

        $timestamp = isset($parts['t'][0]) && ctype_digit($parts['t'][0]) ? (int) $parts['t'][0] : null;
        if ($timestamp === null || ($parts['v1'] ?? []) === []) {
            throw new InvalidWebhook('invalid_signature');
        }

        if (abs(time() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw new InvalidWebhook('timestamp_out_of_tolerance');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        $valid = collect($parts['v1'])->contains(fn (string $sig) => hash_equals($expected, $sig));

        if (! $valid) {
            throw new InvalidWebhook('invalid_signature');
        }

        $eventId = trim((string) $request->header('X-Shopbonus-Event-Id', ''));
        $topic = (string) $request->header('X-Shopbonus-Topic', '');

        if ($eventId === '' || strlen($eventId) > 100 || ! in_array($topic, self::TOPICS, true)) {
            throw new InvalidWebhook('invalid_event_headers');
        }

        return new VerifiedEvent('evt:'.$eventId, $topic, $topic === 'ping' ? VerifiedEvent::KIND_PING : VerifiedEvent::KIND_ORDER);
    }

    /** A banner click ID has our fixed shape; anything else is ignored. */
    public static function clickId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^bc_[A-Za-z0-9]{24}$/', $value) === 1 ? $value : null;
    }

    public static function assignmentToken(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^bx_[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $currency = $this->str($order['currency'] ?? null);
        $currency = Money::isCurrency($currency) ? $currency : null;
        $amount = $order['total_minor'] ?? null;

        return new NormalizedOrder(
            externalId: $this->str($order['id'] ?? null),
            reference: $this->str($order['reference'] ?? null),
            firstName: $this->str($customer['first_name'] ?? null),
            email: $this->str($customer['email'] ?? null),
            phone: $this->str($customer['phone'] ?? null),
            amountMinor: is_int($amount) && $amount >= 0 ? $amount : null,
            currency: $currency,
            paymentStatus: PaymentStatus::tryFrom((string) ($order['payment_status'] ?? '')) ?? PaymentStatus::Unknown,
            orderStatus: $this->str($order['status'] ?? null),
            placedAt: $this->date($order['created_at'] ?? null),
            paidAt: $this->date($order['paid_at'] ?? null),
            cancelledAt: $this->date($order['cancelled_at'] ?? null),
            refundedAt: $this->date($order['refunded_at'] ?? null),
            refundedMinor: is_int($order['refunded_minor'] ?? null) ? $order['refunded_minor'] : null,
            providerUpdatedAt: $this->date($order['updated_at'] ?? null),
            couponCodes: array_values(array_filter(array_map(fn ($c) => $this->str($c), is_array($order['coupon_codes'] ?? null) ? $order['coupon_codes'] : []))),
            contactConsent: is_bool($customer['contact_consent'] ?? null) ? $customer['contact_consent'] : null,
            attributionClickId: self::clickId($order['attribution']['click_id'] ?? null),
            bannerAssignmentToken: self::assignmentToken($order['attribution']['assignment_token'] ?? null),
        );
    }
}
