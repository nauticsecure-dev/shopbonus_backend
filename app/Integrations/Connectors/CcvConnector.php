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
use App\Integrations\Http\WooCommerceApi;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** CCV Shop REST v1, not the unrelated CCV PSP. Read-only creation-window discovery. */
final class CcvConnector extends WebhookConnector implements PagedOrderReader
{
    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::Ccv;
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::Polling;
    }

    public function connectRules(): array
    {
        return ['store_url' => ['required', 'url:https', 'max:255'], 'webshop_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'],
            'api_key' => ['required', 'string', 'min:16', 'max:255', 'regex:/\A[A-Za-z0-9._-]+\z/D'], 'api_secret' => ['required', 'string', 'min:16', 'max:255']];
    }

    public function connect(WebshopConnection $connection, array $input): array
    {
        $url = rtrim($input['store_url'], '/');
        $p = parse_url($url);
        if (! is_array($p) || ($p['scheme'] ?? null) !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['query']) || isset($p['fragment']) || isset($p['path'])) {
            throw new ApiException('Use a public HTTPS store origin without a path, port or credentials.', 422, 'invalid_store_url');
        }
        DomainName::fromInput($url);
        $connection->external_shop_ref = $input['webshop_id'];

        return ['store_url' => $url, 'api_key' => $input['api_key'], 'api_secret' => $input['api_secret']];
    }

    public function setupInstructions(WebshopConnection $connection, string $webhookUrl): array
    {
        return ['format' => 'ccv-shop-rest-v1', 'live_verified' => false, 'steps' => [
            'Create merchant-authorized CCV Shop API public/secret keys with webshop, domains and orders read permissions; supply the webshop ID and exact verified HTTPS store origin.',
            'Native requests use x-public, x-date and HMAC-SHA512 x-hash over the exact GET URI including its query. No CCV payment-service credentials are accepted.',
            'Creation-window polling discovers completed orders. Historical payment changes require explicit order-ID recovery; no updated-since feed is claimed.',
            'Paid is the native marked-as-paid flag. Settlement/payment timestamps, refund cash amounts and consent remain unknown; no browser event substitutes for them.',
            'This connector rejects webhooks: a separate provider-authenticated subscription contract is required. Revoke the keys in CCV Shop when disconnecting.',
        ]];
    }

    public function verifyIncomingEvent(WebshopConnection $connection, Request $request): VerifiedEvent
    {
        throw new InvalidWebhook('polling_only');
    }

    /** @param array<string,string|int> $query
     * @return array<string,mixed>
     */
    private function read(WebshopConnection $connection, string $path, array $query = []): array
    {
        $origin = $connection->credential('store_url');
        $public = $connection->credential('api_key');
        $secret = $connection->credential('api_secret');
        if ($origin === null || $public === null || $secret === null) {
            throw ApiException::conflict('Configure native CCV Shop read credentials.', 'provider_credentials_missing');
        }
        $shop = $connection->webshop;
        if (! $shop->isOwnershipVerified() || DomainName::fromInput($origin) !== strtolower((string) $shop->verified_domain)) {
            throw ApiException::conflict('Verify ownership of the exact CCV store domain.', 'store_identity_unverified');
        }
        $uri = '/api/rest/v1'.$path;
        $signedUri = $uri.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $time = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $hash = hash_hmac('sha512', $public.'|GET|'.$signedUri.'||'.$time, $secret);

        return app(PinnedJsonReader::class)->read($connection, $origin.$uri,
            ['headers' => ['x-public' => $public, 'x-date' => $time, 'x-hash' => $hash]], 'GET', $query,
            credentialIdentity: hash('sha256', $public.'|'.$secret))['data'];
    }

    private function identity(WebshopConnection $connection): void
    {
        $id = WooCommerceApi::orderId((string) $connection->external_shop_ref);
        $store = $this->read($connection, '/webshops/'.$id.'/');
        $origin = $connection->credential('store_url');
        if ((string) ($store['id'] ?? '') !== $id || ($store['is_multishop_system'] ?? null) !== false || ($store['is_salespos'] ?? null) !== false
            || ! is_string($store['api_root'] ?? null) || rtrim($store['api_root'], '/') !== $origin.'/api/rest/v1') {
            throw ApiException::conflict('CCV native webshop identity is not bound to this origin.', 'provider_store_mismatch');
        }
        $domains = $this->read($connection, '/webshops/'.$id.'/domains/')['items'] ?? null;
        if (! is_array($domains) || ! array_is_list($domains) || count($domains) > 100) {
            throw new ApiException('The provider domains response is invalid.', 502, 'provider_response_invalid');
        }
        $matched = false;
        foreach ($domains as $domain) {
            if (is_array($domain) && ($domain['ssldomain'] ?? null) === true && is_string($domain['domainname'] ?? null)
                && DomainName::fromInput($domain['domainname']) === strtolower((string) $connection->webshop->verified_domain)) {
                $matched = true;
            }
        }
        if (! $matched) {
            throw ApiException::conflict('CCV has not confirmed the verified domain for this webshop.', 'provider_store_mismatch');
        }
    }

    public function testConnection(WebshopConnection $connection): array
    {
        $this->identity($connection);
        $this->read($connection, '/orders/', ['start' => 0, 'size' => 1]);

        return ['ok' => true, 'code' => 'api_shop_identity_and_order_scope_observed'];
    }

    public function fetchOrder(WebshopConnection $connection, string $externalId): array
    {
        $id = WooCommerceApi::orderId($externalId);
        $this->identity($connection);
        $order = $this->read($connection, '/orders/'.$id.'/');
        if ((string) ($order['id'] ?? '') !== $id || ($order['is_completed'] ?? null) !== true || ! is_bool($order['paid'] ?? null)
            || ! is_int($order['status'] ?? null) || $order['status'] < 1 || $order['status'] > 14) {
            throw new ApiException('The native order identity or completion state is invalid.', 502, 'provider_order_invalid');
        }

        return ['native_order' => $order];
    }

    public function discoverOrders(WebshopConnection $connection, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        if ($cursor !== null && ! preg_match('/\A(?:0|[1-9][0-9]{0,7})\z/D', $cursor)) {
            throw new \InvalidArgumentException('Invalid native cursor.');
        }
        $start = (int) ($cursor ?? 0);
        $this->identity($connection);
        $page = $this->read($connection, '/orders/', ['start' => $start, 'size' => 25, 'orderby' => 'id',
            'min_create_date' => $since->utc()->format('Y-m-d'), 'max_create_date' => $until->utc()->addDay()->format('Y-m-d'), 'is_completed' => 'true']);
        $rows = $page['items'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 25 || ($page['start'] ?? null) !== $start || (! is_int($page['size'] ?? null) || $page['size'] < count($rows) || $page['size'] > 25)
            || ! array_key_exists('next', $page) || ($page['next'] !== null && ! is_string($page['next']))) {
            throw new ApiException('Invalid native pagination.', 502, 'provider_pagination_invalid');
        }
        if ($page['next'] !== null) {
            $parts = parse_url($page['next']);
            $q = [];
            parse_str($parts['query'] ?? '', $q);
            if (count($rows) !== 25 || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== parse_url((string) $connection->credential('store_url'), PHP_URL_HOST)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || ($parts['path'] ?? null) !== '/api/rest/v1/orders/'
                || (string) ($q['start'] ?? '') !== (string) ($start + 25)) {
                throw new ApiException('Invalid native pagination.', 502, 'provider_pagination_invalid');
            }
        }
        $ids = [];
        $last = 0;
        foreach ($rows as $row) {
            $id = WooCommerceApi::orderId((string) ($row['id'] ?? ''));
            $at = $this->date($row['create_date'] ?? null);
            if ((int) $id <= $last || $at === null) {
                throw new ApiException('Invalid native pagination.', 502, 'provider_pagination_invalid');
            }
            $last = (int) $id;
            if ($at->greaterThanOrEqualTo($since) && $at->lessThanOrEqualTo($until)) {
                $ids[] = $id;
            }
        }

        return ['ids' => $ids, 'next' => $page['next'] === null ? null : (string) ($start + 25)];
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $o = is_array($payload['native_order'] ?? null) ? $payload['native_order'] : [];
        $currency = Money::isCurrency($o['currency'] ?? null) ? $o['currency'] : null;
        $amount = null;
        try {
            if ($currency !== null && (is_string($o['total_price'] ?? null) || is_int($o['total_price'] ?? null))) {
                $amount = Money::toMinor((string) $o['total_price'], $currency);
            }
        } catch (\InvalidArgumentException) {
        }
        if ($amount !== null && $amount < 0) {
            $amount = null;
        }
        $status = match (true) {
            ($o['status'] ?? null) === 8 => PaymentStatus::Cancelled,($o['paid'] ?? null) === true => PaymentStatus::Paid,($o['paid'] ?? null) === false => PaymentStatus::Pending,default => PaymentStatus::Unknown
        };

        return new NormalizedOrder(externalId: $this->str($o['id'] ?? null), reference: $this->str($o['ordernumber_full'] ?? null),
            firstName: $this->str($o['customer']['billingaddress']['first_name'] ?? null), email: $this->str($o['customer']['email'] ?? null),
            phone: $this->str($o['customer']['billingaddress']['telephone'] ?? null), amountMinor: $amount, currency: $amount === null ? null : $currency,
            paymentStatus: $status, orderStatus: is_int($o['status'] ?? null) ? (string) $o['status'] : null, placedAt: $this->date($o['create_date'] ?? null),
            paidAt: null, cancelledAt: null, refundedAt: null, refundedMinor: null, providerUpdatedAt: null);
    }
}
