<?php

namespace App\Integrations\Connectors;

use App\Enums\ConnectionMethod;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\CatalogReader;
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

final class BigCommerceConnector extends WebhookConnector implements CatalogReader, OrderNotification, PagedOrderReader
{
    public const TOPICS = ['store/order/created', 'store/order/updated', 'store/order/statusUpdated', 'store/order/refund/created', 'store/order/transaction/created'];

    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::BigCommerce;
    }

    public function method(): ConnectionMethod
    {
        return ConnectionMethod::Webhook;
    }

    public function connectRules(): array
    {
        return ['store_hash' => ['required', 'string', 'regex:/\A[a-z0-9]{3,32}\z/D'], 'access_token' => ['required', 'string', 'min:16', 'max:2048', 'regex:/\A[A-Za-z0-9_-]+\z/D'], 'client_secret' => ['required', 'string', 'min:16', 'max:255']];
    }

    public function connect(WebshopConnection $c, array $input): array
    {
        $c->external_shop_ref = $input['store_hash'];

        return ['access_token' => $input['access_token'], 'client_secret' => $input['client_secret']];
    }

    public function setupInstructions(WebshopConnection $c, string $url): array
    {
        return ['format' => 'bigcommerce-standard-webhooks', 'webhook_url' => $url, 'store_hash' => $c->external_shop_ref, 'topics' => self::TOPICS, 'steps' => ['Configure a merchant-authorized app/store token with Orders, Order Transactions and Information read scopes.', 'Register HTTPS hooks through POST /stores/{store_hash}/v3/hooks. This receiver requires documented Standard Webhooks signatures using the app client secret. A payload hash alone is not authentication.', 'Widget publication and real order/payment/refund acceptance remain merchant-controlled and unverified.'], 'live_verified' => false];
    }

    public function verifyIncomingEvent(WebshopConnection $c, Request $r): VerifiedEvent
    {
        $raw = $this->rawBody($r);
        $id = $r->header('webhook-id');
        $time = $r->header('webhook-timestamp');
        $signatures = $r->header('webhook-signature');
        if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) || ! is_string($time) || ! preg_match('/\A[0-9]{10}\z/D', $time) || abs(now()->getTimestamp() - (int) $time) > 300 || ! is_string($signatures) || strlen($signatures) > 2048) {
            throw new InvalidWebhook('invalid_signature');
        }
        $expected = 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$time.'.'.$raw, $this->requireSecret($c, 'client_secret'), true));
        $valid = false;
        foreach (explode(' ', $signatures) as $signature) {
            $valid = hash_equals($expected, $signature) || $valid;
        }
        if (! $valid) {
            throw new InvalidWebhook('invalid_signature');
        }
        try {
            $p = ExactJson::decode($raw);
        } catch (\JsonException) {
            throw new InvalidWebhook('invalid_json');
        }
        if (! is_array($p) || ($p['producer'] ?? null) !== 'stores/'.$c->external_shop_ref) {
            throw new InvalidWebhook('store_mismatch');
        }
        $topic = $p['scope'] ?? null;
        $lookup = in_array($topic, self::TOPICS, true);
        if ($lookup && $this->notificationOrderId($c, $raw) === null) {
            throw new InvalidWebhook('invalid_order_id');
        }

        return new VerifiedEvent($id, is_string($topic) ? $topic : null, $lookup ? VerifiedEvent::KIND_ORDER_LOOKUP : VerifiedEvent::KIND_OTHER);
    }

    public function notificationOrderId(WebshopConnection $c, string $raw): ?string
    {
        try {
            $p = ExactJson::decode($raw);
            $data = $p['data'] ?? [];
            // Transaction notifications use order_id; refund notifications use data.id for the order.
            $id = in_array($p['scope'] ?? null, ['store/order/transaction/created'], true) ? ($data['order_id'] ?? null) : ($data['id'] ?? null);

            return WooCommerceApi::orderId((string) $id);
        } catch (\JsonException|\InvalidArgumentException|ApiException) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<mixed>
     */
    private function read(WebshopConnection $c, string $path, array $query = [], bool $allowEmpty = false): array
    {
        if (! preg_match('/\A[a-z0-9]{3,32}\z/D', (string) $c->external_shop_ref) || $c->credential('access_token') === null) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }

        return app(PinnedJsonReader::class)->read($c, 'https://api.bigcommerce.com/stores/'.$c->external_shop_ref.$path, ['headers' => ['X-Auth-Token' => $c->credential('access_token')]], 'GET', $query, allowEmpty: $allowEmpty)['data'];
    }

    private function identity(WebshopConnection $c): void
    {
        $s = $this->read($c, '/v2/store');
        if (($s['id'] ?? null) !== $c->external_shop_ref) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
        if (! $c->webshop->isOwnershipVerified() || ! is_string($s['secure_url'] ?? null) || DomainName::fromInput($s['secure_url']) !== strtolower((string) $c->webshop->verified_domain)) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }
        if (($s['features']['multi_storefront_enabled'] ?? false) === true) {
            throw ApiException::conflict('Multi-storefront channel ownership requires a separate verified mapping.', 'provider_channel_mapping_required');
        }
    }

    public function testConnection(WebshopConnection $c): array
    {
        $this->identity($c);
        $this->read($c, '/v2/orders', ['limit' => 1]);

        return ['ok' => true, 'code' => 'api_shop_identity_and_order_scope_observed'];
    }

    /** @return list<array<string,mixed>> */
    private function collection(WebshopConnection $c, string $path): array
    {
        $p = $this->read($c, $path, ['limit' => 100, 'page' => 1]);
        $rows = $p['data'] ?? null;
        $pagination = $p['meta']['pagination'] ?? [];
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || count($rows) === 100 || ($pagination['total_pages'] ?? 1) > 1) {
            throw ApiException::conflict('This order requires bounded background reconciliation.', 'provider_pagination_limit');
        }
        foreach ($rows as $r) {
            if (! is_array($r)) {
                throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
            }
        }

        return $rows;
    }

    public function fetchOrder(WebshopConnection $c, string $id): array
    {
        $id = WooCommerceApi::orderId($id);
        $this->identity($c);
        $p = $this->read($c, '/v2/orders/'.$id);
        if ((string) ($p['id'] ?? '') !== $id || ! Money::isCurrency($p['default_currency_code'] ?? null) || ($p['currency_code'] ?? null) !== $p['default_currency_code']) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }

        return ['order' => $p, 'transactions' => $this->collection($c, '/v3/orders/'.$id.'/transactions'), 'refunds' => $this->collection($c, '/v3/orders/'.$id.'/payment_actions/refunds')];
    }

    public function discoverOrders(WebshopConnection $c, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        $page = $cursor === null ? 1 : filter_var($cursor, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 10000) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $this->identity($c);
        $rows = $this->read($c, '/v2/orders', ['page' => $page, 'limit' => 25, 'min_date_modified' => $since->utc()->toIso8601String(), 'max_date_modified' => $until->utc()->toIso8601String(), 'sort' => 'date_modified:asc']);
        if (! array_is_list($rows) || count($rows) > 25) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = WooCommerceApi::orderId((string) ($r['id'] ?? ''));
        }

        return ['ids' => $ids, 'next' => count($ids) === 25 ? (string) ($page + 1) : null];
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        $p = $payload['order'] ?? [];
        $currency = Money::isCurrency($p['default_currency_code'] ?? null) && ($p['currency_code'] ?? null) === $p['default_currency_code'] ? $p['default_currency_code'] : null;
        $total = $refund = null;
        $captured = 0;
        $paidAt = $refundAt = null;
        try {
            if ($currency === null) {
                throw new \InvalidArgumentException;
            }
            $total = Money::toMinor($p['total_inc_tax'] ?? '', $currency);
            $refund = Money::toMinor($p['refunded_amount'] ?? '', $currency);
            if ($total < 0 || $refund < 0 || $refund > $total) {
                throw new \InvalidArgumentException;
            }
            $seen = [];
            foreach ($payload['transactions'] ?? [] as $tx) {
                $txId = WooCommerceApi::orderId((string) ($tx['id'] ?? ''));
                if (isset($seen[$txId])) {
                    throw new \InvalidArgumentException;
                }
                $seen[$txId] = true;
                if (($tx['status'] ?? null) !== 'ok' || ! in_array($tx['event'] ?? null, ['purchase', 'capture'], true) || ($tx['test'] ?? true) !== false || ($tx['fraud_review'] ?? true) !== false) {
                    continue;
                }
                if (($tx['currency'] ?? null) !== $currency || (string) ($tx['order_id'] ?? '') !== (string) ($p['id'] ?? '')) {
                    throw new \InvalidArgumentException;
                }
                $amount = Money::toMinor($tx['amount'] ?? '', $currency);
                $at = $this->date($tx['date_created'] ?? null);
                if ($amount < 0 || $at === null) {
                    throw new \InvalidArgumentException;
                }
                $captured += $amount;
                if ($paidAt === null || $at->greaterThan($paidAt)) {
                    $paidAt = $at;
                }
            }
            foreach ($payload['refunds'] ?? [] as $r) {
                if ((string) ($r['order_id'] ?? '') !== (string) ($p['id'] ?? '')) {
                    throw new \InvalidArgumentException;
                }
                $at = $this->date($r['created'] ?? null);
                if ($at !== null && ($refundAt === null || $at->greaterThan($refundAt))) {
                    $refundAt = $at;
                }
            }
        } catch (\InvalidArgumentException|\TypeError) {
            $total = $refund = null;
            $paidAt = $refundAt = null;
        }
        $payment = match (true) {
            $total === null => PaymentStatus::Unknown,($p['status_id'] ?? null) === 5 => PaymentStatus::Cancelled,$paidAt !== null && $captured >= $total && $refund === $total && $total > 0 => PaymentStatus::Refunded,$paidAt !== null && $captured >= $total && $refund > 0 => PaymentStatus::PartiallyRefunded,$paidAt !== null && $captured >= $total => PaymentStatus::Paid,default => PaymentStatus::Pending
        };

        return new NormalizedOrder(externalId: $this->str($p['id'] ?? null), reference: $this->str($p['id'] ?? null), firstName: $this->str($p['billing_address']['first_name'] ?? null), email: $this->str($p['billing_address']['email'] ?? null), phone: $this->str($p['billing_address']['phone'] ?? null), amountMinor: $total, currency: $currency, paymentStatus: $payment, orderStatus: $this->str($p['status'] ?? null), placedAt: $this->date($p['date_created'] ?? null), paidAt: $paidAt, cancelledAt: null, refundedAt: $refundAt, refundedMinor: $refund, providerUpdatedAt: $this->date($p['date_modified'] ?? null));
    }

    public function catalogCurrency(WebshopConnection $c): string
    {
        $this->identity($c);
        $code = $this->read($c, '/v2/store')['currency'] ?? null;

        return Money::isCurrency($code) ? $code : throw new ApiException('The provider store currency could not be read.', 502, 'provider_currency_invalid');
    }

    public function catalogPage(WebshopConnection $c, string $kind, ?string $cursor, string $currency): array
    {
        if ($cursor !== null && ! preg_match('/\A[1-9][0-9]{0,4}\z/D', $cursor)) {
            throw new \InvalidArgumentException('Invalid catalog cursor.');
        }
        $page = $cursor === null ? 1 : (int) $cursor;
        $this->identity($c);
        if ($kind === 'products') {
            $p = $this->read($c, '/v3/catalog/products', ['page' => $page, 'limit' => 100, 'include' => 'primary_image']);
            $rows = $p['data'] ?? null;
            $pages = $p['meta']['pagination']['total_pages'] ?? null;
            $more = is_int($pages) && $page < $pages;
            $store = (string) ($this->read($c, '/v2/store')['secure_url'] ?? '');
        } else {
            // v2 lists carry no paging metadata; an empty page is 204.
            $rows = $this->read($c, '/v2/coupons', ['page' => $page, 'limit' => 100], true);
            $more = count($rows) === 100;
            $store = '';
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            throw new ApiException('The provider list response could not be read.', 502, 'provider_response_invalid');
        }
        $items = [];
        foreach ($rows as $r) {
            if (($item = ! is_array($r) ? throw new ApiException('The provider list response could not be read.', 502, 'provider_response_invalid')
                : ($kind === 'products' ? BigCommerceCatalog::product($r, $currency, $store) : BigCommerceCatalog::coupon($r, $currency))) !== null) {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'next' => $more ? (string) ($page + 1) : null];
    }
}
