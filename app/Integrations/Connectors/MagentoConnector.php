<?php

namespace App\Integrations\Connectors;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Contracts\CatalogReader;
use App\Integrations\Contracts\PagedOrderReader;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\Http\WooCommerceApi;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** Adobe Commerce/Magento2.4 self-hosted/PaaS REST only. SaaS tenant API is a separate unsupported deployment. */
final class MagentoConnector extends GenericSignedWebhookConnector implements CatalogReader, PagedOrderReader
{
    public function __construct()
    {
        parent::__construct(EcommercePlatform::Magento);
    }

    public function connectRules(): array
    {
        return ['store_url' => ['nullable', 'url', 'max:255'], 'access_token' => ['required_with:store_url', 'string', 'min:16', 'max:4096', 'regex:/\A[A-Za-z0-9_.-]+\z/D'], 'store_code' => ['required_with:store_url', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_]+\z/D'], 'store_id' => ['required_with:store_url', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D']];
    }

    public function connect(WebshopConnection $c, array $input): array
    {
        $credentials = parent::connect($c, $input);
        if (! empty($input['store_url'])) {
            $base = WooCommerceApi::baseUrl($input['store_url']);
            $c->external_shop_ref = $base.'#store='.$input['store_id'].':'.$input['store_code'];
            $credentials += ['store_url' => $base, 'access_token' => $input['access_token'], 'store_code' => $input['store_code'], 'store_id' => $input['store_id']];
        }

        return $credentials;
    }

    public function setupInstructions(WebshopConnection $c, string $url): array
    {
        return parent::setupInstructions($c, $url) + ['native_version' => 'Magento/Adobe Commerce2.4 self-hosted/PaaS REST', 'native_read_configured' => $c->credential('access_token') !== null, 'native_notice' => 'Configure an approved restricted integration bearer token supported by the deployed security settings, exact store view ID/code and HTTPS installation URL. SaaS tenant endpoints are not supported. Prefer asynchronous Adobe I/O Events or a compatible signed module; do not block checkout with slow synchronous ingestion. No Magento module is included.'];
    }

    /** @param array<string,mixed> $query
     * @return array<mixed>
     */
    private function read(WebshopConnection $c, string $path, array $query = []): array
    {
        $base = $c->credential('store_url');
        $token = $c->credential('access_token');
        $code = $c->credential('store_code');
        $id = $c->credential('store_id');
        if ($base === null || $token === null || $code === null || $id === null || ! preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $code)) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }
        $base = WooCommerceApi::baseUrl($base);
        if (! $c->webshop->isOwnershipVerified() || DomainName::fromInput($base) !== strtolower((string) $c->webshop->verified_domain) || $c->external_shop_ref !== $base.'#store='.$id.':'.$code) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }

        return app(PinnedJsonReader::class)->read($c, $base.'/rest/'.$code.'/V1/'.$path, ['headers' => ['Authorization' => 'Bearer '.$token]], 'GET', $query)['data'];
    }

    private function identity(WebshopConnection $c): void
    {
        $configs = $this->read($c, 'store/storeConfigs');
        $found = false;
        foreach ($configs as $s) {
            if ((string) ($s['id'] ?? '') === $c->credential('store_id') && ($s['code'] ?? null) === $c->credential('store_code') && is_string($s['secure_base_url'] ?? null) && rtrim($s['secure_base_url'], '/') === $c->credential('store_url')) {
                $found = true;
            }
        }
        if (! $found) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
    }

    public function testConnection(WebshopConnection $c): array
    {
        $this->identity($c);

        return ['ok' => true, 'code' => 'api_shop_identity_observed'];
    }

    /** @return list<array<string,mixed>> */
    private function related(WebshopConnection $c, string $resource, string $order): array
    {
        $p = $this->read($c, $resource, ['searchCriteria' => ['filter_groups' => [['filters' => [['field' => 'order_id', 'value' => $order, 'condition_type' => 'eq']]]], 'page_size' => 100, 'current_page' => 1]]);
        $rows = $p['items'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || ! is_int($p['total_count'] ?? null) || $p['total_count'] !== count($rows)) {
            throw ApiException::conflict('This order requires bounded background reconciliation.', 'provider_pagination_limit');
        }
        foreach ($rows as $r) {
            if (! is_array($r) || (string) ($r['order_id'] ?? '') !== $order) {
                throw new ApiException('The provider order data is incomplete.', 502, 'provider_order_invalid');
            }
        }

        return $rows;
    }

    public function fetchOrder(WebshopConnection $c, string $id): array
    {
        $id = WooCommerceApi::orderId($id);
        $this->identity($c);
        $o = $this->read($c, 'orders/'.$id);
        if ((string) ($o['entity_id'] ?? '') !== $id || (string) ($o['store_id'] ?? '') !== $c->credential('store_id')) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }

        return ['magento_order' => $o, 'transactions' => $this->related($c, 'transactions', $id), 'invoices' => $this->related($c, 'invoices', $id), 'creditmemos' => $this->related($c, 'creditmemos', $id)];
    }

    public function discoverOrders(WebshopConnection $c, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        $page = $cursor === null ? 1 : filter_var($cursor, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 10000) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $this->identity($c);
        $p = $this->read($c, 'orders', ['searchCriteria' => ['filter_groups' => [['filters' => [['field' => 'store_id', 'value' => $c->credential('store_id'), 'condition_type' => 'eq']]], ['filters' => [['field' => 'updated_at', 'value' => $since->utc()->format('Y-m-d H:i:s'), 'condition_type' => 'gteq']]], ['filters' => [['field' => 'updated_at', 'value' => $until->utc()->format('Y-m-d H:i:s'), 'condition_type' => 'lteq']]]], 'sort_orders' => [['field' => 'updated_at', 'direction' => 'ASC'], ['field' => 'entity_id', 'direction' => 'ASC']], 'page_size' => 25, 'current_page' => $page]]);
        $rows = $p['items'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 25 || ! is_int($p['total_count'] ?? null)) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($rows as $o) {
            if ((string) ($o['store_id'] ?? '') !== $c->credential('store_id')) {
                throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
            }
            $ids[] = WooCommerceApi::orderId((string) ($o['entity_id'] ?? ''));
        }
        if ($ids === [] && ($page - 1) * 25 < $p['total_count']) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }

        return ['ids' => $ids, 'next' => $page * 25 < $p['total_count'] ? (string) ($page + 1) : null];
    }

    private function utc(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value)) {
            return null;
        }
        try {
            $d = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');

            return $d !== null && $d->format('Y-m-d H:i:s') === $value ? $d : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function normalizeOrder(array $payload): NormalizedOrder
    {
        if (! isset($payload['magento_order'])) {
            return parent::normalizeOrder($payload);
        }
        $o = $payload['magento_order'];
        $currency = Money::isCurrency($o['order_currency_code'] ?? null) ? $o['order_currency_code'] : null;
        $total = $refund = null;
        $paidAt = $refundAt = null;
        $qualified = false;
        try {
            if ($currency === null) {
                throw new \InvalidArgumentException;
            }
            $total = Money::toMinor($o['grand_total'] ?? '', $currency);
            $refund = Money::toMinor($o['total_refunded'] ?? 0, $currency);
            $paid = Money::toMinor($o['total_paid'] ?? 0, $currency);
            if ($total < 0 || $refund < 0 || $refund > $total) {
                throw new \InvalidArgumentException;
            }
            $invoiced = 0;
            $credited = 0;
            $seen = [];
            foreach ($payload['invoices'] ?? [] as $r) {
                $id = (string) ($r['entity_id'] ?? '');
                if ($id === '' || isset($seen['i'.$id]) || ($r['order_currency_code'] ?? null) !== $currency) {
                    throw new \InvalidArgumentException;
                }
                $seen['i'.$id] = true;
                if (($r['state'] ?? null) === 2) {
                    $invoiced += Money::toMinor($r['grand_total'] ?? '', $currency);
                }
            }
            foreach ($payload['creditmemos'] ?? [] as $r) {
                $id = (string) ($r['entity_id'] ?? '');
                if ($id === '' || isset($seen['c'.$id]) || ($r['order_currency_code'] ?? null) !== $currency) {
                    throw new \InvalidArgumentException;
                }
                $seen['c'.$id] = true;
                if (($r['state'] ?? null) === 2) {
                    $credited += Money::toMinor($r['grand_total'] ?? '', $currency);
                }
            }
            foreach ($payload['transactions'] ?? [] as $tx) {
                $id = (string) ($tx['transaction_id'] ?? '');
                if ($id === '' || isset($seen['t'.$id])) {
                    throw new \InvalidArgumentException;
                }
                $seen['t'.$id] = true;
                $at = $this->utc($tx['created_at'] ?? null);
                if (($tx['txn_type'] ?? null) === 'capture' && $at !== null && ($paidAt === null || $at->greaterThan($paidAt))) {
                    $paidAt = $at;
                }
                if (($tx['txn_type'] ?? null) === 'refund' && $at !== null && ($refundAt === null || $at->greaterThan($refundAt))) {
                    $refundAt = $at;
                }
            }
            $qualified = $paid >= $total && $invoiced >= $total && $paidAt !== null && ($refund === 0 || ($credited === $refund && $refundAt !== null));
        } catch (\InvalidArgumentException|\TypeError) {
            $total = $refund = null;
            $paidAt = $refundAt = null;
        }
        $payment = match (true) {
            $total === null => PaymentStatus::Unknown,($o['state'] ?? null) === 'canceled' => PaymentStatus::Cancelled,$qualified && $refund === $total && $total > 0 => PaymentStatus::Refunded,$qualified && $refund > 0 => PaymentStatus::PartiallyRefunded,$qualified => PaymentStatus::Paid,$refund > 0 => PaymentStatus::Unknown,default => PaymentStatus::Pending
        };

        return new NormalizedOrder(externalId: $this->str($o['entity_id'] ?? null), reference: $this->str($o['increment_id'] ?? null), firstName: $this->str($o['customer_firstname'] ?? $o['billing_address']['firstname'] ?? null), email: $this->str($o['customer_email'] ?? null), phone: $this->str($o['billing_address']['telephone'] ?? null), amountMinor: $total, currency: $currency, paymentStatus: $payment, orderStatus: $this->str($o['status'] ?? null), placedAt: $this->utc($o['created_at'] ?? null), paidAt: $paidAt, cancelledAt: null, refundedAt: $refundAt, refundedMinor: $refund, providerUpdatedAt: $this->utc($o['updated_at'] ?? null));
    }

    /** @return array<string,mixed> this connection's store view config, after the identity gate */
    private function storeConfig(WebshopConnection $c): array
    {
        $this->identity($c);
        foreach ($this->read($c, 'store/storeConfigs') as $s) {
            if (is_array($s) && (string) ($s['id'] ?? '') === $c->credential('store_id')) {
                return $s;
            }
        }
        throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
    }

    public function catalogCurrency(WebshopConnection $c): string
    {
        $code = $this->storeConfig($c)['base_currency_code'] ?? null;

        return Money::isCurrency($code) ? $code : throw new ApiException('The provider store currency could not be read.', 502, 'provider_currency_invalid');
    }

    public function catalogPage(WebshopConnection $c, string $kind, ?string $cursor, string $currency): array
    {
        if ($cursor !== null && ! preg_match('/\A[1-9][0-9]{0,4}\z/D', $cursor)) {
            throw new \InvalidArgumentException('Invalid catalog cursor.');
        }
        $page = $cursor === null ? 1 : (int) $cursor;
        $config = $this->storeConfig($c);
        $size = $kind === 'products' ? 100 : 50;
        if ($kind === 'products') {
            $p = $this->read($c, 'products', ['searchCriteria' => ['sort_orders' => [['field' => 'entity_id', 'direction' => 'ASC']], 'page_size' => $size, 'current_page' => $page]]);
        } else {
            $p = $this->read($c, 'salesRules/search', ['searchCriteria' => ['filter_groups' => [['filters' => [['field' => 'coupon_type', 'value' => 'SPECIFIC_COUPON', 'condition_type' => 'eq']]]],
                'sort_orders' => [['field' => 'rule_id', 'direction' => 'ASC']], 'page_size' => $size, 'current_page' => $page]]);
        }
        $rows = $p['items'] ?? null;
        $total = $p['total_count'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > $size || ! is_int($total)) {
            throw new ApiException('The provider list response could not be read.', 502, 'provider_response_invalid');
        }
        $codes = [];
        $ruleIds = array_values(array_filter(array_map(fn ($r) => is_array($r) && is_int($r['rule_id'] ?? null) ? $r['rule_id'] : null, $rows)));
        if ($kind === 'coupons' && $ruleIds !== []) {
            $found = $this->read($c, 'coupons/search', ['searchCriteria' => ['filter_groups' => [['filters' => [['field' => 'rule_id', 'value' => implode(',', $ruleIds), 'condition_type' => 'in']]],
                ['filters' => [['field' => 'is_primary', 'value' => '1', 'condition_type' => 'eq']]]], 'page_size' => 100, 'current_page' => 1]])['items'] ?? [];
            foreach (is_array($found) ? $found : [] as $coupon) {
                if (is_array($coupon) && is_int($coupon['rule_id'] ?? null)) {
                    $codes[$coupon['rule_id']] = $coupon;
                }
            }
        }
        $timezone = is_string($config['timezone'] ?? null) ? $config['timezone'] : 'UTC';
        $items = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                throw new ApiException('The provider list response could not be read.', 502, 'provider_response_invalid');
            }
            if (($item = $kind === 'products' ? MagentoCatalog::product($r, $currency) : MagentoCatalog::coupon($r, $codes[$r['rule_id'] ?? 0] ?? null, $currency, $timezone)) !== null) {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'next' => $rows !== [] && $page * $size < $total ? (string) ($page + 1) : null];
    }
}
