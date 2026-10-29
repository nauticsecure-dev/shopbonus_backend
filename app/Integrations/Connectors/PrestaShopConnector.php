<?php

namespace App\Integrations\Connectors;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\PagedOrderReader;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\Http\WooCommerceApi;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** PrestaShop9 native Webservice reads; legacy Shopbonus signed module contract remains explicit. */
final class PrestaShopConnector extends GenericSignedWebhookConnector implements PagedOrderReader
{
    public function __construct()
    {
        parent::__construct(EcommercePlatform::PrestaShop);
    }

    public function connectRules(): array
    {
        return ['store_url' => ['nullable', 'url', 'max:255'], 'webservice_key' => ['required_with:store_url', 'string', 'min:16', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/D'], 'shop_id' => ['required_with:store_url', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D'], 'timezone' => ['required_with:store_url', 'timezone']];
    }

    public function connect(WebshopConnection $c, array $input): array
    {
        $credentials = parent::connect($c, $input);
        if (! empty($input['store_url'])) {
            $base = WooCommerceApi::baseUrl($input['store_url']);
            $c->external_shop_ref = $base.'#shop='.$input['shop_id'];
            $credentials += ['store_url' => $base, 'webservice_key' => $input['webservice_key'], 'shop_id' => $input['shop_id'], 'timezone' => $input['timezone']];
        }

        return $credentials;
    }

    public function setupInstructions(WebshopConnection $c, string $url): array
    {
        return parent::setupInstructions($c, $url) + ['native_version' => 'PrestaShop9 Webservice JSON', 'native_read_configured' => $c->credential('webservice_key') !== null, 'native_notice' => 'Configure exact multistore shop ID, HTTPS installation URL, restricted GET Webservice key and store timezone. Credit slips are credit documents, not confirmed cash refunds. Native refund qualification remains unavailable without settlement evidence. No installable ZIP is included.'];
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function read(WebshopConnection $c, string $path, array $query = []): array
    {
        $base = $c->credential('store_url');
        $key = $c->credential('webservice_key');
        $shop = $c->credential('shop_id');
        if ($base === null || $key === null || $shop === null) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }
        $base = WooCommerceApi::baseUrl($base);
        if (! $c->webshop->isOwnershipVerified() || DomainName::fromInput($base) !== strtolower((string) $c->webshop->verified_domain) || $c->external_shop_ref !== $base.'#shop='.$shop) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }

        return app(PinnedJsonReader::class)->read($c, $base.'/api/'.$path, ['basic' => [$key, '']], 'GET', ['output_format' => 'JSON', 'id_shop' => $shop] + $query)['data'];
    }

    /**
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>
     */
    private function list(WebshopConnection $c, string $resource, array $query = [], int $limit = 100): array
    {
        $p = $this->read($c, $resource, array_replace(['display' => 'full', 'limit' => $limit], $query));
        $rows = $p[$resource] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) >= $limit) {
            throw ApiException::conflict('This source requires bounded background reconciliation.', 'provider_pagination_limit');
        }
        foreach ($rows as $r) {
            if (! is_array($r)) {
                throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
            }
        }

        return $rows;
    }

    private function identity(WebshopConnection $c): void
    {
        $rows = $this->list($c, 'shop_urls', ['filter[id_shop]' => '['.$c->credential('shop_id').']', 'filter[main]' => '[1]']);
        $base = $c->credential('store_url');
        $match = false;
        foreach ($rows as $r) {
            if ((string) ($r['id_shop'] ?? '') === $c->credential('shop_id') && (string) ($r['active'] ?? '') === '1' && is_string($r['domain_ssl'] ?? null) && DomainName::fromInput($r['domain_ssl']) === DomainName::fromInput((string) $base)) {
                $match = true;
            }
        }
        if (! $match) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
    }

    public function testConnection(WebshopConnection $c): array
    {
        $this->identity($c);

        return ['ok' => true, 'code' => 'api_shop_identity_observed'];
    }

    public function fetchOrder(WebshopConnection $c, string $id): array
    {
        $id = WooCommerceApi::orderId($id);
        $this->identity($c);
        $p = $this->read($c, 'orders/'.$id)['order'] ?? null;
        if (! is_array($p) || (string) ($p['id'] ?? '') !== $id || (string) ($p['id_shop'] ?? '') !== $c->credential('shop_id')) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }
        $currencyId = WooCommerceApi::orderId((string) ($p['id_currency'] ?? ''));
        $customerId = WooCommerceApi::orderId((string) ($p['id_customer'] ?? ''));
        $addressId = WooCommerceApi::orderId((string) ($p['id_address_invoice'] ?? ''));
        $ref = $p['reference'] ?? null;
        if (! is_string($ref) || ! preg_match('/\A[A-Z0-9]{1,9}\z/D', $ref)) {
            throw new ApiException('The provider order data is incomplete.', 502, 'provider_order_invalid');
        }
        // A reference can be shared by split orders. Never assign its full payment to each order.
        $same = $this->list($c, 'orders', ['filter[reference]' => '['.$ref.']', 'display' => '[id,id_shop,reference]']);
        $payments = count($same) === 1 ? $this->list($c, 'order_payments', ['filter[order_reference]' => '['.$ref.']', 'display' => '[id,order_reference,id_currency,amount,date_add]']) : [];

        return ['presta_order' => $p, 'currency' => $this->read($c, 'currencies/'.$currencyId)['currency'] ?? [], 'customer' => $this->read($c, 'customers/'.$customerId)['customer'] ?? [], 'address' => $this->read($c, 'addresses/'.$addressId)['address'] ?? [], 'payments' => $payments, 'credit_slips' => $this->list($c, 'order_slip', ['filter[id_order]' => '['.$id.']']), 'store_timezone' => $c->credential('timezone')];
    }

    public function discoverOrders(WebshopConnection $c, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        $offset = $cursor === null ? 0 : filter_var($cursor, FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0 || $offset > 250000) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $this->identity($c);
        $zone = $c->credential('timezone');
        if ($zone === null) {
            throw ApiException::conflict('Configure the verified store timezone.', 'provider_credentials_missing');
        }
        $p = $this->read($c, 'orders', ['display' => '[id,id_shop]', 'filter[date_upd]' => '['.$since->setTimezone($zone)->format('Y-m-d H:i:s').','.$until->setTimezone($zone)->format('Y-m-d H:i:s').']', 'date' => 1, 'sort' => '[date_upd_ASC,id_ASC]', 'limit' => $offset.',25']);
        $rows = $p['orders'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 25) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($rows as $r) {
            if ((string) ($r['id_shop'] ?? '') !== $c->credential('shop_id')) {
                throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
            }
            $ids[] = WooCommerceApi::orderId((string) ($r['id'] ?? ''));
        }

        return ['ids' => $ids, 'next' => count($ids) === 25 ? (string) ($offset + 25) : null];
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        if (! isset($payload['presta_order'])) {
            return parent::normalizeOrder($payload);
        }
        $p = $payload['presta_order'];
        $iso = $payload['currency']['iso_code'] ?? null;
        $currency = Money::isCurrency($iso) ? $iso : null;
        $amount = null;
        $paidAt = null;
        $sum = 0;
        $seen = [];
        $zone = $payload['store_timezone'] ?? null;
        $date = function (mixed $v) use ($zone): ?CarbonImmutable {
            if (! is_string($v) || ! is_string($zone) || ! in_array($zone, \DateTimeZone::listIdentifiers(), true) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $v)) {
                return null;
            }
            try {
                $d = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $v, $zone);

                if ($d === null || $d->format('Y-m-d H:i:s') !== $v) {
                    return null;
                }
                // A wall-clock time during the autumn overlap has two possible instants.
                // Neither offset may be invented from a timezone name alone.
                $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $v, 'UTC')->getTimestamp();
                $transitions = (new \DateTimeZone($zone))->getTransitions($d->getTimestamp() - 86400, $d->getTimestamp() + 86400);
                for ($i = 1; $i < count($transitions); $i++) {
                    $before = $transitions[$i - 1]['offset'];
                    $after = $transitions[$i]['offset'];
                    $at = $transitions[$i]['ts'];
                    if ($after < $before && $wall >= $at + $after && $wall < $at + $before) {
                        return null;
                    }
                }

                return $d->utc();
            } catch (\Throwable) {
                return null;
            }
        };
        try {
            if ($currency === null) {
                throw new \InvalidArgumentException;
            }
            $amount = Money::toMinor($p['total_paid_tax_incl'] ?? '', $currency);
            if ($amount < 0) {
                throw new \InvalidArgumentException;
            }
            foreach ($payload['payments'] ?? [] as $tx) {
                $id = (string) ($tx['id'] ?? '');
                if ($id === '' || isset($seen[$id]) || ($tx['order_reference'] ?? null) !== ($p['reference'] ?? null) || (string) ($tx['id_currency'] ?? '') !== (string) ($p['id_currency'] ?? '')) {
                    throw new \InvalidArgumentException;
                }
                $seen[$id] = true;
                $minor = Money::toMinor($tx['amount'] ?? '', $currency);
                $at = $date($tx['date_add'] ?? null);
                if ($minor < 0 || $at === null) {
                    throw new \InvalidArgumentException;
                }
                $sum += $minor;
                if ($paidAt === null || $at->greaterThan($paidAt)) {
                    $paidAt = $at;
                }
            }
        } catch (\InvalidArgumentException|\TypeError) {
            $amount = null;
            $paidAt = null;
        }
        $payment = match (true) {
            $amount === null => PaymentStatus::Unknown,! empty($payload['credit_slips']) => PaymentStatus::Unknown,$paidAt !== null && $sum >= $amount => PaymentStatus::Paid,default => PaymentStatus::Pending
        };

        return new NormalizedOrder(externalId: $this->str($p['id'] ?? null), reference: $this->str($p['reference'] ?? null), firstName: $this->str($payload['customer']['firstname'] ?? null), email: $this->str($payload['customer']['email'] ?? null), phone: $this->str($payload['address']['phone_mobile'] ?? $payload['address']['phone'] ?? null), amountMinor: $amount, currency: $currency, paymentStatus: $payment, orderStatus: $this->str($p['current_state'] ?? null), placedAt: $date($p['date_add'] ?? null), paidAt: $paidAt, cancelledAt: null, refundedAt: null, providerUpdatedAt: $date($p['date_upd'] ?? null));
    }
}
