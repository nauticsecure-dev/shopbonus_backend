<?php

namespace App\Integrations\Connectors;

use App\Enums\ConnectionMethod;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\PagedOrderReader;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Integrations\Http\WooCommerceApi;
use App\Integrations\LineItems;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
class WooCommerceConnector extends WebhookConnector implements PagedOrderReader
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
        return ['store_url' => ['nullable', 'required_with:consumer_key,consumer_secret', 'url:https', 'max:255'],
            'consumer_key' => ['nullable', 'required_with:consumer_secret', 'string', 'min:16', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/D'],
            'consumer_secret' => ['nullable', 'required_with:consumer_key', 'string', 'min:16', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/D']];
    }

    /** @return list<string> */
    public function generatedSecrets(): array
    {
        return ['webhook_secret'];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        $credentials = ['webhook_secret' => $this->newSecret()];
        if (! empty($input['store_url'])) {
            $base = WooCommerceApi::baseUrl($input['store_url']);
            $connection->external_shop_ref = $base;
            $credentials['store_url'] = $base;
            if (! empty($input['consumer_key'])) {
                $shop = $connection->webshop;
                if (! $shop->isOwnershipVerified() || strtolower((string) $shop->verified_domain) !== DomainName::fromInput($base)) {
                    throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
                }
                $credentials['consumer_key'] = $input['consumer_key'];
                $credentials['consumer_secret'] = $input['consumer_secret'];
            }
        }

        return $credentials;
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        return [
            'format' => 'woocommerce-webhooks',
            'webhook_url' => $webhookUrl,
            'read_api_configured' => $connection->credential('consumer_key') !== null && $connection->credential('consumer_secret') !== null,
            'read_api_notice' => 'Optional read-only REST credentials require verified store-domain ownership. API tests do not establish live production readiness.',
            'steps' => [
                'In WordPress go to WooCommerce → Settings → Advanced → Webhooks → Add webhook.',
                'Create two webhooks, topics "Order created" and "Order updated", status Active, API version WP REST API Integration v3.',
                'Delivery URL: the webhook URL above. Secret: the secret shown once when this connection was created.',
                'Save, then place a real paid test order. The connection remains Limited until all seven provider verification checks pass.',
            ],
        ];
    }

    public function testConnection(WebshopConnection $connection): array
    {
        $page = $this->readPage($connection, 'orders', perPage: 1);

        return ['ok' => true, 'code' => $page['data'] === [] ? 'api_authenticated_no_orders' : 'api_orders_read_observed'];
    }

    public function fetchOrder(WebshopConnection $connection, string $externalId): array
    {
        $id = WooCommerceApi::orderId($externalId);
        $order = app(WooCommerceApi::class)->get($connection, 'orders/'.$id)['data'];
        if ((! is_int($order['id'] ?? null) && ! is_string($order['id'] ?? null)) || (string) $order['id'] !== $id || ! is_string($order['total'] ?? null) || ! is_string($order['currency'] ?? null) || ! Money::isCurrency($order['currency'])) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }
        try {
            $totalMinor = Money::toMinor($order['total'], $order['currency']);
        } catch (InvalidArgumentException) {
            throw new ApiException('The provider order amount could not be read.', 502, 'provider_order_invalid');
        }
        $refundedMinor = 0;
        $refunds = [];
        for ($page = 1; $page <= 2; $page++) {
            $result = $this->readPage($connection, 'orders/'.$id.'/refunds', $page);
            if ($result['pages'] > 2) {
                throw ApiException::conflict('This order requires a larger background refund reconciliation.', 'provider_pagination_limit');
            }
            foreach ($result['data'] as $refund) {
                if (! is_array($refund) || ! isset($refund['id']) || ! is_string($refund['amount'] ?? null)) {
                    throw new ApiException('The provider refund data is incomplete.', 502, 'provider_refund_invalid');
                }
                try {
                    $minor = Money::toMinor($refund['amount'], $order['currency']);
                    if ($minor < 0 || ($refundedMinor += $minor) > $totalMinor) {
                        throw new InvalidArgumentException('Invalid refund amount.');
                    }
                } catch (InvalidArgumentException) {
                    throw new ApiException('The provider refund data is incomplete.', 502, 'provider_refund_invalid');
                }
                $refunds[] = ['id' => $refund['id'], 'total' => $refund['amount']];
            }
            if (($result['pages'] > 0 && $page >= $result['pages']) || count($result['data']) < 100) {
                $order['refunds'] = $refunds;

                return $order;
            }
        }
        throw ApiException::conflict('This order requires a larger background refund reconciliation.', 'provider_pagination_limit');
    }

    /**
     * Native GET pages only; no provider response URLs are followed.
     *
     * @return array{data:array<mixed>,pages:int}
     */
    public function readPage(WebshopConnection $connection, string $resource, int $page = 1, int $perPage = 100): array
    {
        $result = app(WooCommerceApi::class)->get($connection, $resource, $page, ['per_page' => $perPage]);
        if (! array_is_list($result['data'])) {
            throw new ApiException('The provider list response could not be read.', 502, 'provider_response_invalid');
        }

        foreach ($result['data'] as $item) {
            if (! is_array($item) || (! is_int($item['id'] ?? null) && ! is_string($item['id'] ?? null)) || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $item['id'])) {
                throw new ApiException('The provider list response could not be read.', 502, 'provider_response_invalid');
            }
        }

        return $result;
    }

    public function syncOrders(WebshopConnection $connection, CarbonImmutable $since): iterable
    {
        $until = CarbonImmutable::now();
        for ($page = 1; $page <= WooCommerceApi::MAX_PAGES; $page++) {
            $result = app(WooCommerceApi::class)->get($connection, 'orders', $page, ['modified_after' => $since->utc()->format('Y-m-d\TH:i:s'),
                'modified_before' => $until->utc()->format('Y-m-d\TH:i:s'), 'dates_are_gmt' => 'true', 'orderby' => 'modified', 'order' => 'asc']);
            if (! array_is_list($result['data']) || $result['pages'] > WooCommerceApi::MAX_PAGES) {
                throw ApiException::conflict('Use bounded background pages for this provider backlog.', 'provider_pagination_limit');
            }
            foreach ($result['data'] as $order) {
                if (! is_array($order) || ! isset($order['id'])) {
                    throw new ApiException('The provider order data is incomplete.', 502, 'provider_order_invalid');
                }
                yield $order;
            }
            if (($result['pages'] > 0 && $page >= $result['pages']) || count($result['data']) < 100) {
                return;
            }
        }
        throw ApiException::conflict('Use bounded background pages for this provider backlog.', 'provider_pagination_limit');
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
            couponCodes: array_values(array_filter(array_map(fn ($l) => is_array($l) ? $this->str($l['code'] ?? null) : null, is_array($payload['coupon_lines'] ?? null) ? $payload['coupon_lines'] : []))),
            // NOT VERIFIED: the storefront embed is expected to store order meta "_shopbonus_click_id".
            attributionClickId: GenericSignedWebhookConnector::clickId(collect(is_array($payload['meta_data'] ?? null) ? $payload['meta_data'] : [])
                ->first(fn ($m) => is_array($m) && ($m['key'] ?? null) === '_shopbonus_click_id')['value'] ?? null),
            bannerAssignmentToken: GenericSignedWebhookConnector::assignmentToken(collect(is_array($payload['meta_data'] ?? null) ? $payload['meta_data'] : [])
                ->first(fn ($m) => is_array($m) && ($m['key'] ?? null) === '_shopbonus_assignment_token')['value'] ?? null),
            providerUpdatedAt: $this->date($payload['date_modified_gmt'] ?? null),
            lineItems: LineItems::woo($payload['line_items'] ?? null, $currency),
        );
    }

    public function discoverOrders(WebshopConnection $connection, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        $page = $cursor === null ? 1 : filter_var($cursor, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 10000) {
            throw new InvalidArgumentException('Invalid page.');
        }
        $result = app(WooCommerceApi::class)->get($connection, 'orders', $page, ['per_page' => 25, 'modified_after' => $since->utc()->format('Y-m-d\TH:i:s'), 'modified_before' => $until->utc()->format('Y-m-d\TH:i:s'), 'dates_are_gmt' => true, 'orderby' => 'modified', 'order' => 'asc'], maxPages: 10000);
        if (! array_is_list($result['data']) || count($result['data']) > 25) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($result['data'] as $row) {
            $ids[] = WooCommerceApi::orderId((string) ($row['id'] ?? ''));
        }

        return ['ids' => $ids, 'next' => count($ids) === 25 && ($result['pages'] === 0 || $page < $result['pages']) ? (string) ($page + 1) : null];
    }
}
