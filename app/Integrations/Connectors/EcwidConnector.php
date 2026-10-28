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
use App\Integrations\ProviderOrderId;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\ExactJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class EcwidConnector extends WebhookConnector implements OrderNotification, PagedOrderReader
{
    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::Ecwid;
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::Webhook;
    }

    public function connectRules(): array
    {
        return ['store_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'access_token' => ['required', 'string', 'min:16', 'max:2048', 'regex:/\A[A-Za-z0-9_-]+\z/D'], 'client_secret' => ['required', 'string', 'min:16', 'max:255']];
    }

    public function connect(WebshopConnection $c, array $input): array
    {
        $c->external_shop_ref = $input['store_id'];

        return ['access_token' => $input['access_token'], 'client_secret' => $input['client_secret']];
    }

    public function setupInstructions(WebshopConnection $c, string $url): array
    {
        return ['format' => 'ecwid-notification', 'webhook_url' => $url, 'store_id' => $c->external_shop_ref, 'steps' => ['Register order.created/order.updated for an app with read_orders and store-profile read access. Configure the app client secret separately from its store access token.', 'The webhook triggers a native lookup only. API plan eligibility and exact verified store domain are checked before reads.', 'Ecwid does not provide an immutable order currency or settlement time in the reviewed order response. Monetary mapping stays incomplete; do not activate paid-order automation from these notifications.'], 'live_verified' => false];
    }

    public function verifyIncomingEvent(WebshopConnection $c, Request $r): VerifiedEvent
    {
        try {
            $p = ExactJson::decode($this->rawBody($r));
        } catch (\JsonException) {
            throw new InvalidWebhook('invalid_json');
        }
        if (! is_array($p) || ! is_string($p['eventId'] ?? null) || ! preg_match('/\A[A-Za-z0-9-]{8,128}\z/D', $p['eventId']) || ! is_int($p['eventCreated'] ?? null) || $p['eventCreated'] < 1 || $p['eventCreated'] > now()->getTimestamp() + 300) {
            throw new InvalidWebhook('invalid_event');
        }
        $this->assertBase64Hmac($p['eventCreated'].'.'.$p['eventId'], $this->requireSecret($c, 'client_secret'), $r->header('X-Ecwid-Webhook-Signature'));
        if ((string) ($p['storeId'] ?? '') !== $c->external_shop_ref) {
            throw new InvalidWebhook('store_mismatch');
        }
        $topic = $p['eventType'] ?? null;
        $lookup = in_array($topic, ['order.created', 'order.updated'], true);
        if ($lookup && $this->notificationOrderId($c, $r->getContent()) === null) {
            throw new InvalidWebhook('invalid_order_id');
        }

        return new VerifiedEvent($p['eventId'], is_string($topic) ? $topic : null, $lookup ? VerifiedEvent::KIND_ORDER_LOOKUP : VerifiedEvent::KIND_OTHER);
    }

    public function notificationOrderId(WebshopConnection $c, string $raw): ?string
    {
        try {
            $p = ExactJson::decode($raw);

            return ProviderOrderId::validate($this->platform(), (string) ($p['entityId'] ?? ''));
        } catch (\JsonException|\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function read(WebshopConnection $c, string $path, array $query = []): array
    {
        if (! preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $c->external_shop_ref) || $c->credential('access_token') === null) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }

        return app(PinnedJsonReader::class)->read($c, 'https://app.ecwid.com/api/v3/'.$c->external_shop_ref.$path, ['headers' => ['Authorization' => 'Bearer '.$c->credential('access_token')]], 'GET', $query)['data'];
    }

    private function identity(WebshopConnection $c): void
    {
        $p = $this->read($c, '/profile');
        if ((string) ($p['generalInfo']['storeId'] ?? '') !== $c->external_shop_ref) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
        $url = $p['generalInfo']['storeUrl'] ?? null;
        if (! $c->webshop->isOwnershipVerified() || ! is_string($url) || DomainName::fromInput($url) !== strtolower((string) $c->webshop->verified_domain)) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }
        if (($p['account']['suspended'] ?? true) !== false || ! in_array('API', $p['account']['availableFeatures'] ?? [], true)) {
            throw ApiException::conflict('This store needs an active plan with API access.', 'provider_plan_restricted');
        }
    }

    public function testConnection(WebshopConnection $c): array
    {
        $this->identity($c);
        $this->read($c, '/orders', ['limit' => 1]);

        return ['ok' => true, 'code' => 'api_shop_identity_and_order_scope_observed'];
    }

    public function fetchOrder(WebshopConnection $c, string $id): array
    {
        $id = ProviderOrderId::validate($this->platform(), $id);
        $this->identity($c);
        $p = $this->read($c, '/orders/'.rawurlencode($id));
        if ((string) ($p['internalId'] ?? '') !== $id) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }

        // Canonical numeric internalId is shared by legacy notifications and discovery.
        return ['native_order' => $p, 'requested_order_id' => $id];
    }

    public function discoverOrders(WebshopConnection $c, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        if ($cursor !== null && ! preg_match('/\A[0-9]{1,7}\z/D', $cursor)) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $offset = (int) ($cursor ?? '0');
        $this->identity($c);
        $p = $this->read($c, '/orders', ['updatedFrom' => $since->getTimestamp(), 'updatedTo' => $until->getTimestamp(), 'offset' => $offset, 'limit' => 25]);
        if (! is_array($p['items'] ?? null) || ! array_is_list($p['items']) || count($p['items']) > 25 || ! is_int($p['total'] ?? null) || ! is_int($p['count'] ?? null) || $p['count'] !== count($p['items']) || ($p['offset'] ?? null) !== $offset) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($p['items'] as $row) {
            $ids[] = ProviderOrderId::validate($this->platform(), (string) ($row['internalId'] ?? ''));
        }
        if ($ids === [] && $offset < $p['total']) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }

        return ['ids' => $ids, 'next' => $offset + count($ids) < $p['total'] ? (string) ($offset + count($ids)) : null];
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $p = $payload['native_order'] ?? [];
        $status = match ($p['paymentStatus'] ?? null) {
            'PAID' => PaymentStatus::Paid,'REFUNDED' => PaymentStatus::Refunded,'PARTIALLY_REFUNDED' => PaymentStatus::PartiallyRefunded,'CANCELLED' => PaymentStatus::Cancelled,'AWAITING_PAYMENT','INCOMPLETE' => PaymentStatus::Pending,default => PaymentStatus::Unknown
        };

        return new NormalizedOrder(externalId: $this->str($p['internalId'] ?? null), reference: $this->str($p['vendorOrderNumber'] ?? $p['orderNumber'] ?? null), firstName: $this->str($p['billingPerson']['firstName'] ?? null), email: $this->str($p['email'] ?? null), phone: $this->str($p['billingPerson']['phone'] ?? null), amountMinor: null, currency: null, paymentStatus: $status, orderStatus: $this->str($p['fulfillmentStatus'] ?? null), placedAt: $this->date($p['createDate'] ?? null), paidAt: null, cancelledAt: null, refundedAt: null, providerUpdatedAt: $this->date($p['updateDate'] ?? null));
    }
}
