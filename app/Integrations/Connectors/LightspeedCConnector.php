<?php

namespace App\Integrations\Connectors;

use App\Enums\ConnectionMethod;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\OrderNotification;
use App\Integrations\Contracts\PagedOrderReader;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\Http\WooCommerceApi;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\ExactJson;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Lightspeed eCom C-Series only. Retail and E-Series credentials/endpoints are refused.
 *
 * Official contracts reviewed 2026-10-30:
 * - https://developers.lightspeedhq.com/ecom/introduction/authentication/
 * - https://developers.lightspeedhq.com/ecom/endpoints/order/
 * - https://developers.lightspeedhq.com/ecom/endpoints/shop/
 * - https://developers.lightspeedhq.com/ecom/tutorials/webhooks/
 * - https://ecom-support.lightspeedhq.com/hc/en-us/articles/220320568-API-documentation
 */
final class LightspeedCConnector extends WebhookConnector implements OrderNotification, PagedOrderReader
{
    public const CLUSTERS = [
        'eu1' => 'https://api.webshopapp.com',
        'us1' => 'https://api.shoplightspeed.com',
    ];

    /** itemGroup/itemAction pairs documented for order lifecycle. */
    public const TOPICS = [
        'orders/created',
        'orders/updated',
        'orders/paid',
        'orders/deleted',
        'invoices/created',
        'invoices/updated',
        'returns/created',
        'returns/updated',
    ];

    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::LightspeedC;
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::Webhook;
    }

    public function connectRules(): array
    {
        return [
            'shop_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'cluster' => ['required', 'string', 'in:eu1,us1'],
            'language' => ['required', 'string', 'regex:/\A[a-z]{2}\z/D'],
            'api_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/\A[A-Za-z0-9]+\z/D'],
            'api_secret' => ['required', 'string', 'min:16', 'max:128', 'regex:/\A[A-Za-z0-9]+\z/D'],
        ];
    }

    public function connect(WebshopConnection $c, array $input): array
    {
        $c->external_shop_ref = $input['shop_id'];

        return [
            'cluster' => $input['cluster'],
            'language' => $input['language'],
            'api_key' => $input['api_key'],
            'api_secret' => $input['api_secret'],
        ];
    }

    public function setupInstructions(WebshopConnection $c, string $url): array
    {
        return [
            'format' => 'lightspeed-ecom-webhooks',
            'webhook_url' => $url,
            'shop_id' => $c->external_shop_ref,
            'cluster' => $c->credential('cluster'),
            'topics' => self::TOPICS,
            'steps' => [
                'Requires an Advanced or Professional Lightspeed eCom (C-Series) plan with API access. Retail and E-Series are not supported on this connector.',
                'Store API key/secret via HTTP Basic against the merchant cluster (eu1=api.webshopapp.com, us1=api.shoplightspeed.com) and shop language path.',
                'Register HTTPS webhooks via POST /webhooks.json for orders created/updated/paid and optionally invoices/returns. App-created webhooks must send x-signature = md5(raw_body + api_secret). Merchant-direct hooks without x-signature are refused.',
                'Order.created can arrive before payment. ShopBonus always re-reads GET /orders/{id}.json and maps paymentStatus separately. Confirmation-page tracking is not used.',
                'Banner publication is merchant theme/script placement only; native automatic storefront publish is not implemented. Live plan/order acceptance remains unverified until a real merchant store is authorized.',
            ],
            'live_verified' => false,
        ];
    }

    public function verifyIncomingEvent(WebshopConnection $c, Request $r): VerifiedEvent
    {
        $raw = $this->rawBody($r);
        $shopHeader = $r->header('x-shop-id');
        $clusterHeader = $r->header('x-cluster-id');
        $signature = $r->header('x-signature');
        $cluster = $c->credential('cluster');
        if (! is_string($shopHeader) || $shopHeader !== $c->external_shop_ref || ! is_string($clusterHeader) || $clusterHeader !== $cluster || ! is_string($signature) || ! preg_match('/\A[a-f0-9]{32}\z/D', $signature)) {
            throw new InvalidWebhook('invalid_signature');
        }
        // Documented Lightspeed app webhook validation: md5(raw_payload + api_secret).
        $expected = md5($raw.$this->requireSecret($c, 'api_secret'));
        if (! hash_equals($expected, $signature)) {
            throw new InvalidWebhook('invalid_signature');
        }

        $topic = $this->topicFromPayload($raw);
        $orderId = $this->notificationOrderId($c, $raw);
        $lookup = str_starts_with($topic, 'orders/') && $orderId !== null;
        if (str_starts_with($topic, 'orders/') && $orderId === null) {
            throw new InvalidWebhook('invalid_order_id');
        }
        $eventId = $shopHeader.'.'.$signature.'.'.hash('sha256', $topic.'|'.($orderId ?? $r->header('x-invoice-id') ?? $r->header('x-return-id') ?? 'none'));

        return new VerifiedEvent($eventId, $topic, $lookup ? VerifiedEvent::KIND_ORDER_LOOKUP : VerifiedEvent::KIND_OTHER);
    }

    public function notificationOrderId(WebshopConnection $c, string $raw): ?string
    {
        try {
            $p = ExactJson::decode($raw);
            if (! is_array($p) || ! is_array($p['order'] ?? null)) {
                return null;
            }

            return WooCommerceApi::orderId((string) ($p['order']['id'] ?? ''));
        } catch (\JsonException|\InvalidArgumentException|ApiException) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function read(WebshopConnection $c, string $resource, array $query = []): array
    {
        $cluster = $c->credential('cluster');
        $language = $c->credential('language');
        $key = $c->credential('api_key');
        $secret = $c->credential('api_secret');
        if (! is_string($cluster) || ! isset(self::CLUSTERS[$cluster]) || ! is_string($language) || ! preg_match('/\A[a-z]{2}\z/D', $language) || $key === null || $secret === null || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $c->external_shop_ref)) {
            throw ApiException::conflict('Configure read-only C-Series API credentials before testing the API.', 'provider_credentials_missing');
        }
        $base = self::CLUSTERS[$cluster];
        $data = app(PinnedJsonReader::class)->read(
            $c,
            $base.'/'.$language.'/'.$resource.'.json',
            ['basic' => [$key, $secret]],
            'GET',
            $query,
        )['data'];

        return $data;
    }

    /** @return array{shop:array<string,mixed>,currency:string} */
    private function identity(WebshopConnection $c): array
    {
        $payload = $this->read($c, 'shop');
        $shop = is_array($payload['shop'] ?? null) ? $payload['shop'] : null;
        if ($shop === null || (string) ($shop['id'] ?? '') !== $c->external_shop_ref) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
        $domain = $shop['mainDomain'] ?? null;
        if (! $c->webshop->isOwnershipVerified() || ! is_string($domain) || DomainName::fromInput($domain) !== strtolower((string) $c->webshop->verified_domain)) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }
        // API access requires Advanced/Professional; unauthorized plans fail earlier as provider_authentication_failed.
        if (! in_array($shop['status'] ?? null, ['live', 'test', 'startup', 'demo'], true)) {
            throw ApiException::conflict('This Lightspeed shop status cannot be used for order ingestion.', 'provider_plan_restricted');
        }
        $currency = $shop['currency']['shortcode'] ?? null;
        if (! Money::isCurrency($currency)) {
            throw ApiException::conflict('The shop main currency is missing or not an ISO currency code.', 'provider_currency_unavailable');
        }

        return ['shop' => $shop, 'currency' => $currency];
    }

    public function testConnection(WebshopConnection $c): array
    {
        $this->identity($c);
        $this->read($c, 'orders', ['limit' => 1, 'page' => 1]);

        return ['ok' => true, 'code' => 'api_shop_identity_and_order_scope_observed'];
    }

    public function fetchOrder(WebshopConnection $c, string $id): array
    {
        $id = WooCommerceApi::orderId($id);
        $identity = $this->identity($c);
        $payload = $this->read($c, 'orders/'.$id);
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : null;
        if ($order === null || (string) ($order['id'] ?? '') !== $id) {
            throw new ApiException('The provider returned an unexpected order identity.', 502, 'provider_order_invalid');
        }

        return ['order' => $order, 'shop_currency' => $identity['currency'], 'shop' => $identity['shop']];
    }

    public function discoverOrders(WebshopConnection $c, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        $page = $cursor === null ? 1 : filter_var($cursor, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 10000) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $this->identity($c);
        $payload = $this->read($c, 'orders', [
            'page' => $page,
            'limit' => 25,
            'updated_at_min' => $since->utc()->format('Y-m-d H:i:s'),
            'updated_at_max' => $until->utc()->format('Y-m-d H:i:s'),
        ]);
        $rows = $payload['orders'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 25) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
            }
            $ids[] = WooCommerceApi::orderId((string) ($row['id'] ?? ''));
        }

        return ['ids' => $ids, 'next' => count($ids) === 25 ? (string) ($page + 1) : null];
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $p = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $currency = Money::isCurrency($payload['shop_currency'] ?? null) ? $payload['shop_currency'] : null;
        $total = null;
        try {
            if ($currency === null) {
                throw new \InvalidArgumentException;
            }
            // Order resource has no per-order currency field; amounts use verified shop main currency at read time.
            $price = $p['priceIncl'] ?? null;
            if (! is_string($price) && ! is_int($price)) {
                throw new \InvalidArgumentException;
            }
            $total = Money::toMinor($price, $currency);
            if ($total < 0) {
                throw new \InvalidArgumentException;
            }
        } catch (\InvalidArgumentException|\TypeError) {
            $total = null;
            $currency = null;
        }

        $payment = match ($p['paymentStatus'] ?? null) {
            'paid' => PaymentStatus::Paid,
            'not_paid', 'partially_paid' => PaymentStatus::Pending,
            'cancelled' => PaymentStatus::Cancelled,
            default => PaymentStatus::Unknown,
        };
        // Documented paymentStatus has no refunded value; returns cash amounts are not mapped in this boundary.
        if (($p['status'] ?? null) === 'cancelled' && $payment === PaymentStatus::Pending) {
            $payment = PaymentStatus::Cancelled;
        }

        $first = $this->str($p['firstname'] ?? $p['firstName'] ?? null);

        return new NormalizedOrder(
            externalId: $this->str($p['id'] ?? null),
            reference: $this->str($p['number'] ?? null),
            firstName: $first,
            email: $this->str($p['email'] ?? null),
            phone: $this->str($p['phone'] ?? $p['mobile'] ?? null),
            amountMinor: $total,
            currency: $currency,
            paymentStatus: $payment,
            orderStatus: $this->str($p['status'] ?? null),
            placedAt: $this->date($p['createdAt'] ?? null),
            paidAt: null,
            cancelledAt: ($p['status'] ?? null) === 'cancelled' ? $this->date($p['updatedAt'] ?? null) : null,
            refundedAt: null,
            refundedMinor: null,
            providerUpdatedAt: $this->date($p['updatedAt'] ?? null),
        );
    }

    private function topicFromPayload(string $raw): string
    {
        try {
            $p = ExactJson::decode($raw);
        } catch (\JsonException) {
            throw new InvalidWebhook('invalid_json');
        }
        if (! is_array($p)) {
            throw new InvalidWebhook('invalid_json');
        }
        // Lightspeed does not send itemAction in headers; infer group from the documented resource wrapper.
        // Payment is always re-verified via GET /orders/{id}.json — never from webhook body alone.
        if (isset($p['order']) && is_array($p['order'])) {
            return ($p['order']['paymentStatus'] ?? null) === 'paid' ? 'orders/paid' : 'orders/updated';
        }
        if (isset($p['invoice']) && is_array($p['invoice'])) {
            return 'invoices/updated';
        }
        if (isset($p['return']) && is_array($p['return'])) {
            return 'returns/updated';
        }

        throw new InvalidWebhook('invalid_event');
    }
}
