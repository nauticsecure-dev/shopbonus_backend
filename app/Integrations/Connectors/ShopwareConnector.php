<?php

namespace App\Integrations\Connectors;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\PagedOrderReader;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\Http\WooCommerceApi;
use App\Integrations\ProviderOrderId;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** Shopware6 integration read credentials. Module bridge remains explicit; no invented app-registration handshake. */
final class ShopwareConnector extends GenericSignedWebhookConnector implements PagedOrderReader
{
    public function __construct()
    {
        parent::__construct(EcommercePlatform::Shopware6);
    }

    public function connectRules(): array
    {
        return ['store_url' => ['nullable', 'url', 'max:255'], 'client_id' => ['required_with:store_url', 'string', 'min:16', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/D'], 'client_secret' => ['required_with:store_url', 'string', 'min:16', 'max:255'], 'sales_channel_id' => ['required_with:store_url', 'string', 'regex:/\A[a-f0-9]{32}\z/D']];
    }

    public function connect(WebshopConnection $c, array $input): array
    {
        $credentials = parent::connect($c, $input);
        if (! empty($input['store_url'])) {
            $base = WooCommerceApi::baseUrl($input['store_url']);
            $c->external_shop_ref = $base.'#channel='.$input['sales_channel_id'];
            $credentials += ['store_url' => $base, 'client_id' => $input['client_id'], 'client_secret' => $input['client_secret'], 'sales_channel_id' => $input['sales_channel_id']];
        }

        return $credentials;
    }

    public function setupInstructions(WebshopConnection $c, string $url): array
    {
        return parent::setupInstructions($c, $url) + ['native_version' => 'Shopware6 Admin API', 'native_read_configured' => $c->credential('client_id') !== null, 'native_notice' => 'Restricted integration client credentials enable fixed Admin API reads. Declare order, order_customer, order_address, currency, order_transaction, state_machine_history and sales_channel_domain read permissions. App registration/native app webhooks and CMS publication are separate from the existing Shopbonus signed module bridge. Partial/refunded states lack exact settled-refund evidence and stay unqualified.'];
    }

    private function base(WebshopConnection $c): string
    {
        $base = $c->credential('store_url');
        if ($base === null || $c->credential('client_id') === null || $c->credential('client_secret') === null) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }
        $base = WooCommerceApi::baseUrl($base);
        if (! $c->webshop->isOwnershipVerified() || DomainName::fromInput($base) !== strtolower((string) $c->webshop->verified_domain) || $c->external_shop_ref !== $base.'#channel='.$c->credential('sales_channel_id')) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }

        return $base;
    }

    private function token(WebshopConnection $c): string
    {
        $p = app(PinnedJsonReader::class)->read($c, $this->base($c).'/api/oauth/token', [], 'POST', ['grant_type' => 'client_credentials', 'client_id' => $c->credential('client_id'), 'client_secret' => $c->credential('client_secret')])['data'];
        if (($p['token_type'] ?? null) !== 'Bearer' || ! is_string($p['access_token'] ?? null) || ! preg_match('/\A[A-Za-z0-9_.-]{16,4096}\z/D', $p['access_token']) || ! is_int($p['expires_in'] ?? null) || $p['expires_in'] < 60) {
            throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
        }

        return $p['access_token'];
    }

    /** @param array<string,mixed> $criteria
     * @return array<string,mixed>
     */
    private function search(WebshopConnection $c, string $token, string $entity, array $criteria): array
    {
        return app(PinnedJsonReader::class)->read($c, $this->base($c).'/api/search/'.$entity, ['headers' => ['Authorization' => 'Bearer '.$token]], 'POST', $criteria)['data'];
    }

    private function identity(WebshopConnection $c, string $token): void
    {
        $p = $this->search($c, $token, 'sales-channel-domain', ['limit' => 2, 'filter' => [['type' => 'equals', 'field' => 'salesChannelId', 'value' => $c->credential('sales_channel_id')], ['type' => 'equals', 'field' => 'url', 'value' => $this->base($c)]]]);
        $rows = $p['data'] ?? [];
        $match = false;
        foreach ($rows as $r) {
            if (($r['salesChannelId'] ?? null) === $c->credential('sales_channel_id') && ($r['url'] ?? null) === $this->base($c)) {
                $match = true;
            }
        }
        if (! $match) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
    }

    public function testConnection(WebshopConnection $c): array
    {
        $token = $this->token($c);
        $this->identity($c, $token);

        return ['ok' => true, 'code' => 'api_shop_identity_observed'];
    }

    public function fetchOrder(WebshopConnection $c, string $id): array
    {
        $id = ProviderOrderId::validate($this->platform(), $id);
        $token = $this->token($c);
        $this->identity($c, $token);
        $p = $this->search($c, $token, 'order', ['ids' => [$id], 'limit' => 1, 'associations' => ['currency' => [], 'orderCustomer' => [], 'billingAddress' => [], 'stateMachineState' => [], 'transactions' => ['limit' => 2, 'associations' => ['stateMachineState' => []]]]]);
        $rows = $p['data'] ?? [];
        $o = count($rows) === 1 ? $rows[0] : null;
        if (! is_array($o) || ($o['id'] ?? null) !== $id || ($o['salesChannelId'] ?? null) !== $c->credential('sales_channel_id')) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }
        $history = [];
        $txs = $o['transactions'] ?? [];
        if (is_array($txs) && count($txs) === 1 && ($txs[0]['stateMachineState']['technicalName'] ?? null) === 'paid') {
            $h = $this->search($c, $token, 'state-machine-history', ['limit' => 2, 'filter' => [['type' => 'equals', 'field' => 'referencedId', 'value' => $txs[0]['id']], ['type' => 'equals', 'field' => 'toStateMachineState.technicalName', 'value' => 'paid']], 'associations' => ['toStateMachineState' => []], 'sort' => [['field' => 'createdAt', 'order' => 'DESC']]]);
            $history = $h['data'] ?? [];
        }

        return ['shopware_order' => $o, 'paid_state_history' => $history];
    }

    public function discoverOrders(WebshopConnection $c, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        $page = $cursor === null ? 1 : filter_var($cursor, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 10000) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $token = $this->token($c);
        $this->identity($c, $token);
        $p = $this->search($c, $token, 'order', ['page' => $page, 'limit' => 25, 'filter' => [['type' => 'equals', 'field' => 'salesChannelId', 'value' => $c->credential('sales_channel_id')], ['type' => 'multi', 'operator' => 'OR', 'queries' => [['type' => 'range', 'field' => 'updatedAt', 'parameters' => ['gte' => $since->utc()->toIso8601String(), 'lte' => $until->utc()->toIso8601String()]], ['type' => 'range', 'field' => 'createdAt', 'parameters' => ['gte' => $since->utc()->toIso8601String(), 'lte' => $until->utc()->toIso8601String()]]]]], 'sort' => [['field' => 'createdAt', 'order' => 'ASC'], ['field' => 'id', 'order' => 'ASC']]]);
        $rows = $p['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 25) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($rows as $r) {
            if (($r['salesChannelId'] ?? null) !== $c->credential('sales_channel_id')) {
                throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
            }
            $ids[] = ProviderOrderId::validate($this->platform(), (string) ($r['id'] ?? ''));
        }

        return ['ids' => $ids, 'next' => count($ids) === 25 ? (string) ($page + 1) : null];
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        if (! isset($payload['shopware_order'])) {
            return parent::normalizeOrder($payload);
        }
        $o = $payload['shopware_order'];
        $iso = $o['currency']['isoCode'] ?? null;
        $currency = Money::isCurrency($iso) ? $iso : null;
        $amount = null;
        $paidAt = null;
        $payment = PaymentStatus::Unknown;
        try {
            if ($currency === null) {
                throw new \InvalidArgumentException;
            }
            $amount = Money::toMinor($o['amountTotal'] ?? '', $currency);
            if ($amount < 0) {
                throw new \InvalidArgumentException;
            }
            $txs = $o['transactions'] ?? [];
            $payment = PaymentStatus::Pending;
            if (is_array($txs) && count($txs) === 1) {
                $tx = $txs[0];
                $state = $tx['stateMachineState']['technicalName'] ?? null;
                if (in_array($state, ['refunded', 'refunded_partially'], true)) {
                    $payment = PaymentStatus::Unknown;
                } elseif ($state === 'paid' && Money::toMinor($tx['amount']['totalPrice'] ?? '', $currency) >= $amount) {
                    foreach ($payload['paid_state_history'] ?? [] as $h) {
                        if (($h['referencedId'] ?? null) === ($tx['id'] ?? null) && ($h['toStateMachineState']['technicalName'] ?? null) === 'paid') {
                            $paidAt = $this->date($h['createdAt'] ?? null);
                            break;
                        }
                    }
                    if ($paidAt !== null) {
                        $payment = PaymentStatus::Paid;
                    }
                }
            }
        } catch (\InvalidArgumentException|\TypeError) {
            $amount = null;
            $paidAt = null;
            $payment = PaymentStatus::Unknown;
        }

        return new NormalizedOrder(externalId: $this->str($o['id'] ?? null), reference: $this->str($o['orderNumber'] ?? null), firstName: $this->str($o['orderCustomer']['firstName'] ?? null), email: $this->str($o['orderCustomer']['email'] ?? null), phone: $this->str($o['billingAddress']['phoneNumber'] ?? null), amountMinor: $amount, currency: $currency, paymentStatus: $payment, orderStatus: $this->str($o['stateMachineState']['technicalName'] ?? null), placedAt: $this->date($o['orderDateTime'] ?? null), paidAt: $paidAt, cancelledAt: null, refundedAt: null, providerUpdatedAt: $this->date($o['updatedAt'] ?? $o['createdAt'] ?? null));
    }
}
