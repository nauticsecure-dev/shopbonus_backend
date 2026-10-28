<?php

namespace App\Integrations\Http;

use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;

/** Read-only native REST v3; credentials never enter URLs, redirects, exceptions or responses. */
class WooCommerceApi
{
    public const MAX_PAGES = 5;

    public function __construct(private PinnedJsonReader $reader) {}

    public static function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', strtolower($parts['host']))
            || str_contains($parts['host'], '..')) {
            throw new ApiException('Use an HTTPS store URL without credentials, query, fragment or a custom port.', 422, 'store_url_invalid');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (! preg_match('~\A(?:/[A-Za-z0-9_-]+)*\z~D', $path)) {
            throw new ApiException('Use the WordPress installation URL, not an API path or shop page.', 422, 'store_url_invalid');
        }

        DomainName::fromInput($parts['host']);

        return 'https://'.strtolower($parts['host']).$path;
    }

    public static function orderId(string $id): string
    {
        if (! preg_match('/\A[1-9][0-9]{0,18}\z/D', $id)) {
            throw new ApiException('A positive numeric provider order ID is required.', 422, 'provider_order_id_invalid');
        }

        return $id;
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array{data:array<mixed>,pages:int}
     */
    public function get(WebshopConnection $connection, string $resource, int $page = 1, array $query = []): array
    {
        if ($connection->status === ConnectionStatus::Disconnected) {
            throw ApiException::conflict('This connection is disconnected. Create a new one.', 'connection_disconnected');
        }
        if (! preg_match('~\A(?:orders(?:/[1-9][0-9]{0,18}(?:/refunds)?)?|products|coupons)\z~D', $resource)
            || $page < 1 || $page > self::MAX_PAGES) {
            throw new \InvalidArgumentException('Unsupported provider resource or page.');
        }
        $storeUrl = $connection->credential('store_url');
        $key = $connection->credential('consumer_key');
        $secret = $connection->credential('consumer_secret');
        if ($key === null || $secret === null || $storeUrl === null) {
            throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
        }
        $base = self::baseUrl($storeUrl);
        $host = (string) parse_url($base, PHP_URL_HOST);
        $shop = $connection->webshop;
        if (! $shop->isOwnershipVerified() || strtolower((string) $shop->verified_domain) !== DomainName::fromInput($host)) {
            throw ApiException::conflict('Verify ownership of the exact store domain before using its API.', 'store_identity_unverified');
        }
        $query = array_intersect_key($query, array_flip(['per_page', 'modified_after', 'modified_before', 'dates_are_gmt', 'orderby', 'order']));
        $query['page'] = $page;
        $query['per_page'] = max(1, min(100, (int) ($query['per_page'] ?? 100)));
        $result = $this->reader->read($connection, $base.'/wp-json/wc/v3/'.$resource, ['basic' => [$key, $secret]], query: $query);
        $data = $result['data'];
        $pages = $result['headers']['pages'];
        if ($pages !== '' && (! ctype_digit($pages) || strlen($pages) > 7)) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }

        return ['data' => $data, 'pages' => $pages === '' ? 0 : (int) $pages];
    }
}
