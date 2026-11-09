<?php

namespace App\Integrations\Connectors;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\AdobeImsTokens;
use App\Integrations\AmountBreakdown;
use App\Integrations\AssignmentMetadata;
use App\Integrations\Contracts\CatalogReader;
use App\Integrations\Contracts\CouponLookup;
use App\Integrations\Contracts\OrderNotification;
use App\Integrations\Contracts\PagedOrderReader;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\Http\WooCommerceApi;
use App\Integrations\LineItems;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\ExactJson;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Native Magento/PaaS REST and explicitly scoped Adobe Commerce SaaS REST. Event delivery is deployment-specific. */
final class MagentoConnector extends GenericSignedWebhookConnector implements CatalogReader, CouponLookup, OrderNotification, PagedOrderReader
{
    public function __construct()
    {
        parent::__construct(EcommercePlatform::Magento);
    }

    public function verifyIncomingEvent(WebshopConnection $connection, Request $request): VerifiedEvent
    {
        $event = parent::verifyIncomingEvent($connection, $request);
        try {
            $payload = ExactJson::decode($this->rawBody($request));
        } catch (\JsonException) {
            throw new InvalidWebhook('invalid_json');
        }
        if (is_array($payload) && array_key_exists('shopbonus_magento_lookup_v1', $payload)) {
            if ($event->kind !== VerifiedEvent::KIND_ORDER || $this->notificationOrderId($connection, $request->getContent()) === null) {
                throw new InvalidWebhook('invalid_order_id');
            }

            return new VerifiedEvent($event->dedupeKey, $event->topic, VerifiedEvent::KIND_ORDER_LOOKUP);
        }

        return $event;
    }

    public function notificationOrderId(WebshopConnection $connection, string $raw): ?string
    {
        try {
            $p = ExactJson::decode($raw);
            if (! is_array($p) || ($p['shopbonus_magento_lookup_v1'] ?? false) !== true || ! is_string($p['store_id'] ?? null) || ! is_string($p['store_code'] ?? null)
                || $p['store_id'] !== $connection->credential('store_id') || $p['store_code'] !== $connection->credential('store_code')) {
                return null;
            }

            return WooCommerceApi::orderId(is_string($p['order_id'] ?? null) ? $p['order_id'] : '');
        } catch (\JsonException|\InvalidArgumentException|ApiException) {
            return null;
        }
    }

    public function connectRules(): array
    {
        return ['deployment' => ['sometimes', 'in:self_hosted,saas'], 'saas_api_url' => ['required_if:deployment,saas', 'nullable', 'string', 'max:150', 'regex:~\Ahttps://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.api\.commerce\.adobe\.com/[A-Za-z0-9_-]{1,100}/?\z~D'], 'store_url' => ['required_if:deployment,saas', 'nullable', 'url', 'max:255'], 'ims_client_id' => ['prohibited_unless:deployment,saas', 'required_with:ims_client_secret', 'string', 'regex:/\A[A-Za-z0-9_-]{8,255}\z/D'], 'ims_client_secret' => ['prohibited_unless:deployment,saas', 'required_with:ims_client_id', 'string', 'min:16', 'max:4096', 'not_regex:/[\r\n]/'], 'access_token' => ['exclude_without:store_url', 'required_without:ims_client_id', 'prohibits:ims_client_id,ims_client_secret', 'string', 'min:16', 'max:4096', 'regex:/\A[A-Za-z0-9_.-]+\z/D'], 'store_code' => ['required_with:store_url', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_]+\z/D'], 'store_id' => ['required_with:store_url', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/D']];
    }

    public function connect(WebshopConnection $c, array $input): array
    {
        $credentials = parent::connect($c, $input);
        if (! empty($input['store_url'])) {
            $base = WooCommerceApi::baseUrl($input['store_url']);
            $api = ($input['deployment'] ?? 'self_hosted') === 'saas' ? rtrim($input['saas_api_url'], '/') : $base;
            $c->external_shop_ref = $api.'#store='.$input['store_id'].':'.$input['store_code'];
            $credentials += ['store_url' => $base, 'store_code' => $input['store_code'], 'store_id' => $input['store_id'], 'deployment' => $input['deployment'] ?? 'self_hosted'];
            if (isset($input['access_token'])) {
                $credentials['access_token'] = $input['access_token'];
            }
            if (isset($input['ims_client_id'], $input['ims_client_secret'])) {
                $credentials['ims_client_id'] = $input['ims_client_id'];
                $credentials['ims_client_secret'] = $input['ims_client_secret'];
            }
            if (($input['deployment'] ?? null) === 'saas') {
                $credentials['saas_api_url'] = $api;
            }
        }

        return $credentials;
    }

    public function setupInstructions(WebshopConnection $c, string $url): array
    {
        $saas = $c->credential('deployment') === 'saas';

        return parent::setupInstructions($c, $url) + ['native_version' => $saas ? 'Adobe Commerce as a Cloud Service REST' : 'Magento/Adobe Commerce2.4 self-hosted/PaaS REST',
            'native_read_configured' => $c->credential('access_token') !== null || $c->credential('ims_client_id') !== null,
            'native_notice' => $saas ? 'SaaS uses the exact tenant API URL, current IMS bearer token and Store header; storefront domain and store ID/code are verified independently. Optional encrypted IMS client credentials renew access tokens automatically; manually supplied tokens remain deployment-managed. Candidate private Adobe I/O/App Builder source: /downloads/integrations/shopbonus-adobe-appbuilder-source.zip. Runtime Action registration/signature validation, scoped event setup, actual deployment and delivery acceptance remain NOT RUN; PHP modules cannot run on SaaS.'
                : __('Configure restricted native REST credentials and exact verified store ID/code. Candidate asynchronous module: :module. Commit-time local outbox only; configure encrypted per-store signing settings and cron. Native installation/order/refund/cleanup acceptance NOT RUN.', ['module' => url('/downloads/integrations/shopbonus-magento2-async.zip')])];
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
        if ($base === null || ($token === null && $c->credential('ims_client_id') === null) || $code === null || $id === null || ! preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $code)) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }
        $base = WooCommerceApi::baseUrl($base);
        $saas = $c->credential('deployment') === 'saas';
        $api = $saas ? $c->credential('saas_api_url') : $base;
        if ($saas && (! is_string($api) || ! preg_match('~\Ahttps://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.api\.commerce\.adobe\.com/[A-Za-z0-9_-]{1,100}\z~D', $api) || $code === 'all')) {
            throw ApiException::conflict('Configure the exact scoped Adobe SaaS tenant URL.', 'provider_tenant_invalid');
        }
        if (! $c->webshop->isOwnershipVerified() || DomainName::fromInput($base) !== strtolower((string) $c->webshop->verified_domain) || $c->external_shop_ref !== $api.'#store='.$id.':'.$code) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }
        if ($saas) {
            $token = app(AdobeImsTokens::class)->accessToken($c);
        }
        $headers = ['Authorization' => 'Bearer '.$token];
        if ($saas) {
            $headers['Store'] = $code;
        }
        $endpoint = $saas ? $api.'/V1/'.$path : $base.'/rest/'.$code.'/V1/'.$path;

        return app(PinnedJsonReader::class)->read($c, $endpoint, ['headers' => $headers], 'GET', $query)['data'];
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

        return new NormalizedOrder(externalId: $this->str($o['entity_id'] ?? null), reference: $this->str($o['increment_id'] ?? null), firstName: $this->str($o['customer_firstname'] ?? $o['billing_address']['firstname'] ?? null), email: $this->str($o['customer_email'] ?? null), phone: $this->str($o['billing_address']['telephone'] ?? null), amountMinor: $total, currency: $currency, paymentStatus: $payment, orderStatus: $this->str($o['status'] ?? null), placedAt: $this->utc($o['created_at'] ?? null), paidAt: $paidAt, cancelledAt: null, refundedAt: $refundAt, refundedMinor: $refund, providerUpdatedAt: $this->utc($o['updated_at'] ?? null),
            bannerAssignmentToken: AssignmentMetadata::fromMap($o['extension_attributes'] ?? null),
            lineItems: LineItems::magento($o['items'] ?? null, $currency, $o['entity_id'] ?? null),
            amountBreakdown: AmountBreakdown::make($currency, 'magento_rest_order', ['original_total' => isset($o['grand_total']) ? (string) $o['grand_total'] : null, 'tax' => isset($o['tax_amount']) ? (string) $o['tax_amount'] : null, 'shipping' => isset($o['shipping_amount']) ? (string) $o['shipping_amount'] : null, 'discount' => isset($o['discount_amount']) && is_numeric($o['discount_amount']) && $o['discount_amount'] <= 0 ? ltrim((string) $o['discount_amount'], '-') : null]));
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

    public function lookupCoupon(WebshopConnection $connection, string $code): array
    {
        $store = $this->storeConfig($connection);
        $currency = $store['base_currency_code'] ?? null;
        if (! Money::isCurrency($currency)) {
            throw new ApiException('The provider store currency could not be read.', 502, 'provider_currency_invalid');
        }
        $response = $this->read($connection, 'coupons/search', ['searchCriteria' => ['filter_groups' => [['filters' => [['field' => 'code', 'value' => $code, 'condition_type' => 'eq']]]], 'page_size' => 2, 'current_page' => 1]]);
        $rows = $response['items'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ($response['total_count'] ?? null) !== count($rows) || count($rows) > 1) {
            throw ApiException::conflict('The native coupon lookup is incomplete.', 'provider_coupon_lookup_incomplete');
        }
        if ($rows === []) {
            return ['coupon' => null, 'currency' => $currency];
        }
        $coupon = $rows[0];
        if (! is_array($coupon) || ($coupon['code'] ?? null) !== $code || ! is_int($coupon['coupon_id'] ?? null) || $coupon['coupon_id'] < 1
            || ! is_int($coupon['rule_id'] ?? null) || $coupon['rule_id'] < 1) {
            throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
        }
        // Detailed reads carry condition/action trees which list responses can omit.
        $detail = $this->read($connection, 'coupons/'.$coupon['coupon_id']);
        $rule = $this->read($connection, 'salesRules/'.$coupon['rule_id']);
        if (($detail['coupon_id'] ?? null) !== $coupon['coupon_id'] || ($detail['code'] ?? null) !== $code
            || ($detail['rule_id'] ?? null) !== $coupon['rule_id'] || ($rule['rule_id'] ?? null) !== $coupon['rule_id']) {
            throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
        }
        $groups = $this->read($connection, 'customerGroups/search', ['searchCriteria' => ['page_size' => 100, 'current_page' => 1]]);
        $items = $groups['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || ($groups['total_count'] ?? null) !== count($items) || count($items) > 100 || $items === []) {
            throw ApiException::conflict('The native coupon lookup is incomplete.', 'provider_coupon_lookup_incomplete');
        }
        $ids = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_int($item['id'] ?? null) || $item['id'] < 0 || in_array($item['id'], $ids, true)) {
                throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
            }
            $ids[] = $item['id'];
        }

        $active = $this->read($connection, 'salesRules/search', ['searchCriteria' => ['filter_groups' => [['filters' => [['field' => 'is_active', 'value' => '1', 'condition_type' => 'eq']]]], 'page_size' => 100, 'current_page' => 1]]);
        $rules = $active['items'] ?? null;
        if (! is_array($rules) || ! array_is_list($rules) || ($active['total_count'] ?? null) !== count($rules) || count($rules) > 100) {
            throw ApiException::conflict('The native coupon lookup is incomplete.', 'provider_coupon_lookup_incomplete');
        }
        $idsSeen = [];
        foreach ($rules as $item) {
            if (! is_array($item) || ! is_int($item['rule_id'] ?? null) || $item['rule_id'] < 1 || in_array($item['rule_id'], $idsSeen, true)) {
                throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
            }
            $idsSeen[] = $item['rule_id'];
        }
        if (($rule['is_active'] ?? null) === true && ! in_array($coupon['rule_id'], $idsSeen, true)) {
            throw new ApiException('The native coupon response is invalid.', 502, 'provider_coupon_invalid');
        }
        $otherRules = count(array_filter($idsSeen, fn ($id) => $id !== $coupon['rule_id']));

        return ['currency' => $currency, 'coupon' => ['rule' => $rule, 'coupon' => $detail, 'store' => $store, 'customer_groups' => $ids, 'other_rules' => $otherRules]];
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
