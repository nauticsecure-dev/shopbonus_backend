<?php

namespace App\Integrations\Http;

use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\ConnectionResource;
use App\Integrations\ShopifyOAuth;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** Fixed reviewed native operations only; no caller-supplied GraphQL or callback URL. */
class ShopifyApi
{
    public const VERSION = '2026-10';

    private const SHOP = 'query ShopbonusIdentity { shop { id myshopifyDomain primaryDomain { host } } currentAppInstallation { accessScopes { handle } } }';

    private const FIELDS = 'id legacyResourceId name email phone billingAddress { firstName phone } createdAt updatedAt cancelledAt displayFinancialStatus fullyPaid totalPriceSet { shopMoney { amount currencyCode } } currentTotalPriceSet { shopMoney { amount currencyCode } } totalTaxSet { shopMoney { amount currencyCode } } totalDiscountsSet { shopMoney { amount currencyCode } } taxesIncluded totalRefundedSet { shopMoney { amount currencyCode } } discountCodes customAttributes { key value }';

    public function __construct(private PinnedJsonReader $reader) {}

    /** @return array{ok:bool,code:string} */
    public function test(WebshopConnection $connection): array
    {
        $this->identity($connection);

        return ['ok' => true, 'code' => 'api_shop_identity_and_order_scope_observed'];
    }

    /** @return array<string,mixed> */
    public function order(WebshopConnection $connection, string $id): array
    {
        $id = WooCommerceApi::orderId($id);
        $this->identity($connection);
        $query = 'query ShopbonusOrder($id: ID!) { order(id: $id) { '.self::FIELDS.' lineItems(first: 100) { nodes { id product { id } sku quantity discountedTotalSet(withCodeDiscounts: true) { shopMoney { amount currencyCode } } } pageInfo { hasNextPage } } transactions(first: 101) { kind status processedAt } } }';
        $data = $this->query($connection, $query, ['id' => 'gid://shopify/Order/'.$id], true);
        $order = $data['order'] ?? null;
        if ($order === null) {
            throw ApiException::conflict('The provider order is unavailable under the current access scopes.', 'provider_order_unavailable');
        }
        if (! is_array($order) || ($order['id'] ?? null) !== 'gid://shopify/Order/'.$id
            || (! is_int($order['legacyResourceId'] ?? null) && ! is_string($order['legacyResourceId'] ?? null))
            || (string) $order['legacyResourceId'] !== $id) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }
        $this->validateMoney($order);
        if (! is_array($order['transactions'] ?? null) || ! array_is_list($order['transactions']) || count($order['transactions']) > 100) {
            throw ApiException::conflict('This order requires bounded background transaction reconciliation.', 'provider_pagination_limit');
        }

        return $order;
    }

    /** @return iterable<array<string,mixed>> */
    public function orders(WebshopConnection $connection, CarbonImmutable $since): iterable
    {
        $this->identity($connection);
        $filter = "updated_at:>='".$since->utc()->format('Y-m-d\TH:i:s\Z')."' updated_at:<='".CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z')."'";
        $cursor = null;
        $seen = [];
        $query = 'query ShopbonusOrders($after: String, $filter: String!) { orders(first: 25, after: $after, query: $filter, sortKey: UPDATED_AT) { nodes { '.self::FIELDS.' } pageInfo { hasNextPage endCursor } } }';
        for ($page = 0; $page < 5; $page++) {
            $data = $this->query($connection, $query, ['after' => $cursor, 'filter' => $filter], true);
            $list = $data['orders']['nodes'] ?? null;
            $info = $data['orders']['pageInfo'] ?? null;
            if (! is_array($list) || ! array_is_list($list) || count($list) > 25 || ! is_array($info) || ! is_bool($info['hasNextPage'] ?? null)) {
                throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
            }
            foreach ($list as $order) {
                if (! is_array($order) || ! is_string($order['id'] ?? null) || ! preg_match('~\Agid://shopify/Order/[1-9][0-9]{0,18}\z~D', $order['id'])) {
                    throw new ApiException('The provider order data is incomplete.', 502, 'provider_order_invalid');
                }
                $this->validateMoney($order);
                yield $order;
            }
            if (! $info['hasNextPage']) {
                return;
            }
            $cursor = $info['endCursor'] ?? null;
            if (! is_string($cursor) || $cursor === '' || strlen($cursor) > 1024 || isset($seen[$cursor])) {
                throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
            }
            $seen[$cursor] = true;
        }
        throw ApiException::conflict('Use bounded background pages for this provider backlog.', 'provider_pagination_limit');
    }

    /** @return array<string,mixed> */
    public function products(WebshopConnection $connection, ?string $after = null): array
    {
        if ($after !== null && (strlen($after) > 1024 || $after === '')) {
            throw new \InvalidArgumentException('Invalid native cursor.');
        }
        $this->identity($connection, 'read_products');
        $data = $this->query($connection, 'query ShopbonusProducts($after: String) { products(first: 25, after: $after) { nodes { id title handle updatedAt } pageInfo { hasNextPage endCursor } } }', ['after' => $after]);
        $products = $data['products'] ?? null;
        if (! is_array($products) || ! is_array($products['nodes'] ?? null) || ! array_is_list($products['nodes']) || count($products['nodes']) > 25
            || ! is_bool($products['pageInfo']['hasNextPage'] ?? null)) {
            throw new ApiException('The provider list response could not be read.', 502, 'provider_response_invalid');
        }

        return $products;
    }

    private const CATALOG_PRODUCTS = 'query ShopbonusCatalogProducts($after: String) { products(first: 50, after: $after, sortKey: ID) { nodes { id legacyResourceId title description productType status onlineStoreUrl updatedAt totalInventory tracksInventory featuredMedia { preview { image { url } } } priceRangeV2 { minVariantPrice { amount currencyCode } } variants(first: 1) { nodes { sku } } collections(first: 10) { nodes { legacyResourceId title } } } pageInfo { hasNextPage endCursor } } }';

    private const DISCOUNT_COMMON = 'status endsAt updatedAt usageLimit appliesOncePerCustomer asyncUsageCount codesCount { count } codes(first: 2) { nodes { code } } combinesWith { orderDiscounts productDiscounts shippingDiscounts } context { __typename } minimumRequirement { __typename ... on DiscountMinimumSubtotal { greaterThanOrEqualToSubtotal { amount currencyCode } } }';

    private const CATALOG_DISCOUNTS = 'query ShopbonusCatalogDiscounts($after: String) { discountNodes(first: 25, after: $after, query: "method:code", sortKey: ID) { nodes { id discount { __typename ... on DiscountCodeBasic { '.self::DISCOUNT_COMMON.' customerGets { value { __typename ... on DiscountPercentage { percentage } ... on DiscountAmount { amount { amount currencyCode } appliesOnEachItem } } items { __typename ... on DiscountProducts { products(first: 10) { nodes { legacyResourceId } pageInfo { hasNextPage } } } ... on DiscountCollections { collections(first: 10) { nodes { legacyResourceId } pageInfo { hasNextPage } } } } } } ... on DiscountCodeFreeShipping { '.self::DISCOUNT_COMMON.' } } } pageInfo { hasNextPage endCursor } } }';

    /** Store currency after the identity/domain gate (no extra scope). */
    public function shopCurrency(WebshopConnection $connection): ?string
    {
        $this->identity($connection, null);
        $code = $this->query($connection, 'query ShopbonusCurrency { shop { currencyCode } }')['shop']['currencyCode'] ?? null;

        return is_string($code) ? $code : null;
    }

    /**
     * One fixed catalog page: products (read_products) or code discounts (read_discounts).
     *
     * @return array{nodes: list<mixed>, next: string|null}
     */
    public function catalog(WebshopConnection $connection, string $kind, ?string $after): array
    {
        if (! in_array($kind, ['products', 'coupons'], true) || ($after !== null && ($after === '' || strlen($after) > 1024))) {
            throw new \InvalidArgumentException('Invalid catalog kind or native cursor.');
        }
        $this->identity($connection, $kind === 'products' ? 'read_products' : 'read_discounts');
        $root = $kind === 'products' ? 'products' : 'discountNodes';
        $data = $this->query($connection, $kind === 'products' ? self::CATALOG_PRODUCTS : self::CATALOG_DISCOUNTS, ['after' => $after]);
        $list = $data[$root] ?? null;
        $max = $kind === 'products' ? 50 : 25;
        if (! is_array($list) || ! is_array($list['nodes'] ?? null) || ! array_is_list($list['nodes']) || count($list['nodes']) > $max
            || ! is_bool($list['pageInfo']['hasNextPage'] ?? null)) {
            throw new ApiException('The provider list response could not be read.', 502, 'provider_response_invalid');
        }
        $next = $list['pageInfo']['hasNextPage'] ? ($list['pageInfo']['endCursor'] ?? null) : null;
        if ($list['pageInfo']['hasNextPage'] && (! is_string($next) || $next === '' || strlen($next) > 1024)) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }

        return ['nodes' => $list['nodes'], 'next' => $next];
    }

    /** @return array{ids:list<string>,next:?string} */
    public function discover(WebshopConnection $c, CarbonImmutable $since, CarbonImmutable $until, ?string $cursor): array
    {
        if ($cursor !== null && strlen($cursor) > 1024) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }
        $this->identity($c);
        $filter = "updated_at:>='".$since->utc()->format('Y-m-d\TH:i:s\Z')."' updated_at:<='".$until->utc()->format('Y-m-d\TH:i:s\Z')."'";
        $data = $this->query($c, 'query ShopbonusDiscovery($after: String, $filter: String!) { orders(first: 25, after: $after, query: $filter, sortKey: UPDATED_AT) { nodes { id legacyResourceId } pageInfo { hasNextPage endCursor } } }', ['after' => $cursor, 'filter' => $filter]);
        $nodes = $data['orders']['nodes'] ?? null;
        $info = $data['orders']['pageInfo'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes) || count($nodes) > 25 || ! is_array($info) || ! is_bool($info['hasNextPage'] ?? null)) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        $ids = [];
        foreach ($nodes as $node) {
            $id = WooCommerceApi::orderId((string) ($node['legacyResourceId'] ?? ''));
            if (($node['id'] ?? null) !== 'gid://shopify/Order/'.$id) {
                throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
            }$ids[] = $id;
        }
        $next = $info['hasNextPage'] ? ($info['endCursor'] ?? null) : null;
        if ($info['hasNextPage'] && (! is_string($next) || $next === '' || strlen($next) > 1024)) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }

        return ['ids' => $ids, 'next' => $next];
    }

    public const WEBHOOK_TOPICS = ['ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_PAID', 'ORDERS_CANCELLED', 'ORDERS_FULFILLED', 'REFUNDS_CREATE', 'APP_UNINSTALLED'];

    /** @return list<array<string,mixed>> */
    public function webhooks(WebshopConnection $connection, string $topic): array
    {
        $this->webhookTopic($topic);
        $this->identity($connection);
        $data = $this->query($connection, 'query ShopbonusWebhooks($topics: [WebhookSubscriptionTopic!]) { webhookSubscriptions(first: 100, topics: $topics) { nodes { id topic uri format filter includeFields } pageInfo { hasNextPage } } }', ['topics' => [$topic]]);
        $list = $data['webhookSubscriptions'] ?? null;
        if (! is_array($list) || ! is_array($list['nodes'] ?? null) || ! array_is_list($list['nodes']) || count($list['nodes']) > 100
            || ($list['pageInfo']['hasNextPage'] ?? null) !== false) {
            throw ApiException::conflict('The subscription list is incomplete. Review the provider configuration.', 'provider_hook_list_incomplete');
        }
        foreach ($list['nodes'] as $item) {
            if (! is_array($item) || ($item['topic'] ?? null) !== $topic || ! is_string($item['id'] ?? null)
                || ! preg_match('~\Agid://shopify/WebhookSubscription/[1-9][0-9]{0,18}\z~D', $item['id']) || ! is_string($item['uri'] ?? null)) {
                throw new ApiException('The provider subscription response could not be read.', 502, 'provider_hook_invalid');
            }
        }

        return $list['nodes'];
    }

    /** Fixed supported topic and server-owned callback only; no caller-supplied GraphQL or endpoint.
     * @return array<string,mixed>
     */
    public function createWebhook(WebshopConnection $connection, string $topic): array
    {
        $this->webhookTopic($topic);
        $uri = ConnectionResource::webhookUrl($connection);
        $parts = parse_url($uri);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])) {
            throw ApiException::conflict('Configure a public HTTPS application URL before provisioning webhooks.', 'webhook_callback_invalid');
        }
        $this->identity($connection);
        $data = $this->query($connection, 'mutation ShopbonusCreateWebhook($topic: WebhookSubscriptionTopic!, $subscription: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $subscription) { webhookSubscription { id topic uri format filter includeFields } userErrors { field message } } }', ['topic' => $topic, 'subscription' => ['uri' => $uri, 'format' => 'JSON']]);
        $result = $data['webhookSubscriptionCreate'] ?? null;
        if (! is_array($result) || ! is_array($result['userErrors'] ?? null) || $result['userErrors'] !== []) {
            throw ApiException::conflict('The provider did not confirm subscription creation. Inspect the native subscription list before retrying.', 'provider_hook_create_unconfirmed');
        }
        $hook = $result['webhookSubscription'] ?? null;
        if (! is_array($hook) || ($hook['topic'] ?? null) !== $topic || ($hook['uri'] ?? null) !== $uri || ! is_string($hook['id'] ?? null)
            || ! preg_match('~\Agid://shopify/WebhookSubscription/[1-9][0-9]{0,18}\z~D', $hook['id'])) {
            throw new ApiException('The provider subscription response could not be read.', 502, 'provider_hook_invalid');
        }

        return $hook;
    }

    private function webhookTopic(string $topic): void
    {
        if (! in_array($topic, self::WEBHOOK_TOPICS, true)) {
            throw new \InvalidArgumentException('Unsupported webhook topic.');
        }
    }

    private function identity(WebshopConnection $connection, ?string $scope = 'read_orders'): void
    {
        $data = $this->query($connection, self::SHOP);
        $shop = $data['shop'] ?? null;
        $scopes = $data['currentAppInstallation']['accessScopes'] ?? null;
        $workspace = $connection->webshop;
        if (! is_array($shop) || ($shop['myshopifyDomain'] ?? null) !== $connection->external_shop_ref || ! is_string($shop['primaryDomain']['host'] ?? null)) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
        if (! $workspace->isOwnershipVerified() || ! in_array(strtolower((string) $workspace->verified_domain),
            [DomainName::fromInput($shop['primaryDomain']['host']), DomainName::fromInput($shop['myshopifyDomain'])], true)) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }
        if ($scope === null) {
            return;
        }
        if (! is_array($scopes) || ! array_is_list($scopes)) {
            throw ApiException::conflict('The provider has not proved the required read scopes.', 'provider_scope_missing');
        }
        $granted = array_column($scopes, 'handle');
        if (! in_array($scope, $granted, true) && ! in_array(str_replace('read_', 'write_', $scope), $granted, true)) {
            throw ApiException::conflict('The provider has not proved the required read scopes.', 'provider_scope_missing');
        }
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    private function query(WebshopConnection $connection, string $query, #[\SensitiveParameter] array $variables = [], bool $allowProtectedFields = false): array
    {
        if ($connection->status === ConnectionStatus::Disconnected) {
            throw ApiException::conflict('This connection is disconnected. Create a new one.', 'connection_disconnected');
        }
        $host = $connection->external_shop_ref;
        $token = app(ShopifyOAuth::class)->accessToken($connection);
        if (! is_string($host) || ! preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/D', $host) || $token === null) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }
        $auth = ['headers' => ['X-Shopify-Access-Token' => $token]];
        $result = $this->reader->read($connection, 'https://'.$host.'/admin/api/'.self::VERSION.'/graphql.json', $auth, 'POST', ['query' => $query, 'variables' => $variables === [] ? (object) [] : $variables]);
        if ($result['headers']['version'] !== self::VERSION) {
            throw ApiException::conflict('The provider API version differs from the reviewed version.', 'provider_version_mismatch');
        }
        $body = $result['data'];
        $errors = $body['errors'] ?? [];
        if (! is_array($errors) || ! array_is_list($errors)) {
            throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
        }
        foreach ($errors as $error) {
            $code = $error['extensions']['code'] ?? null;
            if ($code === 'THROTTLED') {
                $this->reader->pause($connection, $host, $auth, 60);
                throw new ApiException('The provider API rate limit was reached.', 503, 'provider_rate_limited', headers: ['Retry-After' => '60']);
            }
            $path = $error['path'] ?? [];
            $allowed = $allowProtectedFields && $code === 'ACCESS_DENIED' && is_array($path) && $this->protectedPath($path);
            if (! $allowed) {
                throw ApiException::conflict('The provider GraphQL read failed or required protected data is unavailable.', 'provider_graphql_error');
            }
            // A denied field can never become approved PII merely because partial data contains it.
            $target = &$body['data'];
            foreach (array_slice($path, 0, -1) as $segment) {
                if (! is_array($target) || ! array_key_exists($segment, $target)) {
                    unset($target);

                    continue 2;
                }
                $target = &$target[$segment];
            }
            if (is_array($target)) {
                $target[$path[count($path) - 1]] = null;
            }
            unset($target);
        }
        if (! is_array($body['data'] ?? null)) {
            throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
        }

        return $body['data'];
    }

    /** @param array<mixed> $path */
    private function protectedPath(array $path): bool
    {
        if (($path[0] ?? null) === 'order') {
            $rest = array_slice($path, 1);
        } elseif (($path[0] ?? null) === 'orders' && ($path[1] ?? null) === 'nodes'
            && is_int($path[2] ?? null) && $path[2] >= 0 && $path[2] < 25) {
            $rest = array_slice($path, 3);
        } else {
            return false;
        }

        return in_array($rest, [['email'], ['phone'], ['billingAddress'], ['billingAddress', 'firstName'], ['billingAddress', 'phone']], true);
    }

    /** @param array<string,mixed> $order */
    private function validateMoney(array $order): void
    {
        $id = $order['legacyResourceId'] ?? null;
        if ((! is_int($id) && ! is_string($id)) || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $id)
            || ($order['id'] ?? null) !== 'gid://shopify/Order/'.$id) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }
        $total = $order['totalPriceSet']['shopMoney'] ?? [];
        $refund = $order['totalRefundedSet']['shopMoney'] ?? [];
        if (! is_array($total) || ! is_array($refund) || ! is_string($total['amount'] ?? null) || ! Money::isCurrency($total['currencyCode'] ?? null)
            || ! is_string($refund['amount'] ?? null) || ($refund['currencyCode'] ?? null) !== $total['currencyCode']) {
            throw new ApiException('The provider returned an unexpected order identity or currency.', 502, 'provider_order_invalid');
        }
        try {
            $gross = Money::toMinor($total['amount'], $total['currencyCode']);
            $refunded = Money::toMinor($refund['amount'], $total['currencyCode']);
            if ($gross < 0 || $refunded < 0 || $refunded > $gross) {
                throw new \InvalidArgumentException('Invalid native amounts.');
            }
        } catch (\InvalidArgumentException) {
            throw new ApiException('The provider order amount could not be read.', 502, 'provider_order_invalid');
        }
    }
}
