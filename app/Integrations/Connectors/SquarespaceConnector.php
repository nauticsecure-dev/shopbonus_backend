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
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\ProviderOrderId;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** API-key polling. Fulfillment never substitutes for payment evidence. */
final class SquarespaceConnector extends WebhookConnector implements PagedOrderReader
{
    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::Squarespace;
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::Polling;
    }

    public function connectRules(): array
    {
        return ['website_id' => ['required', 'string', 'regex:/\A[a-f0-9]{24}\z/D'], 'api_key' => ['required', 'string', 'min:16', 'max:2048', 'regex:/\A[A-Za-z0-9._-]+\z/D']];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        $connection->external_shop_ref = $input['website_id'];

        return ['api_key' => $input['api_key']];
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        return ['format' => 'squarespace-orders-api-1.0', 'steps' => [
            'Create a restricted Orders read API key on an eligible Squarespace plan; supply its website ID and verify ownership of the exact canonical domain.',
            'Orders use durable incremental polling through the existing integration sync worker. Enable the reviewed connection explicitly in integration sync configuration.',
            'No webhook is accepted with an API key. Payment state and refund totals are mapped; settlement timestamps remain unknown. Test-mode orders are refused before ingestion.',
            'Revoke the API key in Squarespace when disconnecting. Native banner publication is not implemented.',
        ], 'live_verified' => false];
    }

    public function verifyIncomingEvent(WebshopConnection $connection, Request $request): VerifiedEvent
    {
        throw new InvalidWebhook('polling_only');
    }

    /** @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function read(WebshopConnection $connection, string $path, array $query = []): array
    {
        $key = $connection->credential('api_key');
        if ($key === null) {
            throw ApiException::conflict('Configure the provider API key before reading orders.', 'provider_credentials_missing');
        }

        return app(PinnedJsonReader::class)->read($connection, 'https://api.squarespace.com/1.0'.$path,
            ['headers' => ['Authorization' => 'Bearer '.$key, 'User-Agent' => 'Shopbonus/1.0']], 'GET', $query)['data'];
    }

    private function identity(WebshopConnection $connection): void
    {
        $site = $this->read($connection, '/authorization/website');
        if (! is_string($site['id'] ?? null) || $site['id'] !== $connection->external_shop_ref) {
            throw ApiException::conflict('The provider website does not match this connection.', 'provider_store_mismatch');
        }
        if (! $connection->webshop->isOwnershipVerified() || ! is_string($site['url'] ?? null)
            || DomainName::fromInput($site['url']) !== strtolower((string) $connection->webshop->verified_domain)) {
            throw ApiException::conflict('Verify ownership of the exact canonical store domain.', 'store_identity_unverified');
        }
    }

    public function testConnection(WebshopConnection $connection): array
    {
        $this->identity($connection);
        $this->read($connection, '/commerce/orders');

        return ['ok' => true, 'code' => 'api_shop_identity_and_order_scope_observed'];
    }

    public function fetchOrder(WebshopConnection $connection, string $externalId): array
    {
        $id = ProviderOrderId::validate($this->platform(), $externalId);
        $this->identity($connection);
        $order = $this->read($connection, '/commerce/orders/'.$id);
        if (($order['id'] ?? null) !== $id) {
            throw new ApiException('The provider returned a different order.', 502, 'provider_order_invalid');
        }
        if (($order['testmode'] ?? null) !== false || ($order['channel'] ?? null) !== 'web') {
            throw ApiException::conflict('Only observed non-test website orders are supported.', 'provider_order_unsupported');
        }

        return ['native_order' => $order];
    }

    public function discoverOrders(WebshopConnection $connection, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        if ($cursor !== null && ! preg_match('/\A[A-Za-z0-9_+=\/-]{1,2048}\z/D', $cursor)) {
            throw new \InvalidArgumentException('Invalid provider cursor.');
        }
        $this->identity($connection);
        // Provider forbids date ranges together with a cursor. Never follow nextPageUrl.
        $page = $this->read($connection, '/commerce/orders', $cursor !== null ? ['cursor' => $cursor] : [
            'modifiedAfter' => $since->utc()->toIso8601ZuluString(), 'modifiedBefore' => $until->utc()->toIso8601ZuluString(),
            'paymentStates' => 'NOT_CHARGED,AUTHORIZED,PAID,REFUNDED,PENDING,FAILED,REFUND_PENDING,REFUND_FAILED,PARTIALLY_PAID',
        ]);
        $rows = $page['result'] ?? null;
        $more = $page['pagination']['hasNextPage'] ?? null;
        $next = $page['pagination']['nextPageCursor'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 50 || ! is_bool($more)
            || ($more && ($rows === [] || ! is_string($next) || $next === $cursor || ! preg_match('/\A[A-Za-z0-9_+=\/-]{1,2048}\z/D', $next)))) {
            throw new ApiException('Invalid provider pagination.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = ProviderOrderId::validate($this->platform(), is_array($row) && is_string($row['id'] ?? null) ? $row['id'] : '');
        }

        return ['ids' => array_values(array_unique($ids)), 'next' => $more ? $next : null];
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $order = is_array($payload['native_order'] ?? null) ? $payload['native_order'] : [];
        $currency = $order['grandTotal']['currency'] ?? null;
        $currency = Money::isCurrency($currency) ? $currency : null;
        $amount = $this->minor($order['grandTotal'] ?? null, $currency);
        $refund = $this->minor($order['refundedTotal'] ?? null, $currency);
        if ($refund !== null && $amount !== null && $refund > $amount) {
            $refund = null;
        }
        $status = match ($order['paymentState'] ?? null) {
            'PAID' => $amount === null || $refund === null ? PaymentStatus::Unknown : ($refund > 0 ? ($refund === $amount ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded) : PaymentStatus::Paid),
            'REFUNDED' => PaymentStatus::Refunded,
            'NOT_CHARGED', 'AUTHORIZED', 'PENDING', 'PARTIALLY_PAID' => PaymentStatus::Pending,
            'FAILED' => PaymentStatus::Failed,
            default => PaymentStatus::Unknown,
        };
        if (($order['fulfillmentStatus'] ?? null) === 'CANCELED' && ! in_array($status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true)) {
            $status = PaymentStatus::Cancelled;
        }

        return new NormalizedOrder(
            externalId: $this->str($order['id'] ?? null), reference: $this->str($order['orderNumber'] ?? null),
            firstName: $this->str($order['billingAddress']['firstName'] ?? null), email: $this->str($order['customerEmail'] ?? null),
            phone: $this->str($order['billingAddress']['phone'] ?? null), amountMinor: $amount, currency: $amount === null ? null : $currency,
            paymentStatus: $status, orderStatus: $this->str($order['fulfillmentStatus'] ?? null),
            placedAt: $this->date($order['createdOn'] ?? null), paidAt: null, cancelledAt: null, refundedAt: null,
            refundedMinor: $refund, providerUpdatedAt: $this->date($order['modifiedOn'] ?? null),
            // Checkout form answers are shopper input, not a hidden cart-to-order bridge.
            bannerAssignmentToken: null,
        );
    }

    private function minor(mixed $money, ?string $currency): ?int
    {
        if ($currency === null || ! is_array($money) || ($money['currency'] ?? null) !== $currency
            || (! is_string($money['value'] ?? null) && ! is_int($money['value'] ?? null))) {
            return null;
        }
        try {
            $minor = Money::toMinor($money['value'], $currency);

            return $minor >= 0 ? $minor : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
