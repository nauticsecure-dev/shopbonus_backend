<?php

namespace App\Integrations\Http;

use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\PublicAddressGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;

/** Read-only native REST v3; credentials never enter URLs, redirects, exceptions or responses. */
class WooCommerceApi
{
    public const MAX_PAGES = 5;

    public function __construct(private HostResolver $resolver) {}

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
        $cacheKey = 'commerce-api:'.hash_hmac('sha256', $connection->public_id.'|'.$base.'|'.$key.'|'.$secret, (string) config('app.key'));
        $pausedUntil = filter_var(Cache::get($cacheKey.':paused'), FILTER_VALIDATE_INT);
        if ($pausedUntil !== false && $pausedUntil > now()->getTimestamp()) {
            throw new ApiException('Provider reads are temporarily paused. Retry later.', 503, 'provider_circuit_open', headers: ['Retry-After' => (string) ($pausedUntil - now()->getTimestamp())]);
        }
        $addresses = $this->resolver->addresses($host);
        if (! PublicAddressGuard::allPublic($addresses)) {
            throw ApiException::conflict('The store domain must resolve only to public addresses.', 'provider_address_refused');
        }
        $pin = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        // Callers supply only known native pagination/filter fields, never a continuation URL.
        $query = array_intersect_key($query, array_flip(['per_page', 'modified_after', 'modified_before', 'dates_are_gmt', 'orderby', 'order']));
        $query['page'] = $page;
        $query['per_page'] = max(1, min(100, (int) ($query['per_page'] ?? 100)));
        try {
            $response = Http::acceptJson()->withBasicAuth($key, $secret)->withOptions([
                'allow_redirects' => false, 'proxy' => '', 'verify' => true, 'connect_timeout' => 3, 'timeout' => 8, 'stream' => true,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pin}"]],
            ])->get($base.'/wp-json/wc/v3/'.$resource, $query);
        } catch (ConnectionException) {
            $this->failure($cacheKey);
            throw new ApiException('The provider API could not be reached.', 502, 'provider_unreachable');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw ApiException::conflict('The provider rejected these credentials or read permissions.', 'provider_authentication_failed');
        }
        if ($response->status() === 429) {
            $seconds = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);
            $seconds = max(1, min(3600, $seconds === false ? 60 : $seconds));
            Cache::put($cacheKey.':paused', now()->getTimestamp() + $seconds, $seconds);
            throw new ApiException('The provider API rate limit was reached.', 503, 'provider_rate_limited', headers: ['Retry-After' => (string) $seconds]);
        }
        if ($response->status() !== 200) {
            if ($response->status() >= 500) {
                $this->failure($cacheKey);
            }
            throw new ApiException('The provider API did not return a successful read response.', 502, 'provider_read_failed');
        }
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (! $stream->eof() && strlen($body) <= 1048576) {
            $part = $stream->read(min(65536, 1048577 - strlen($body)));
            if ($part === '') {
                break;
            }
            $body .= $part;
        }
        if (strlen($body) > 1048576 || ! $stream->eof()) {
            throw new ApiException('The provider response exceeds the safe read limit.', 502, 'provider_response_too_large');
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
        }
        if (! is_array($data)) {
            throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
        }
        $pages = $response->header('X-WP-TotalPages');
        if ($pages !== '' && (! ctype_digit($pages) || strlen($pages) > 7)) {
            throw new ApiException('The provider pagination metadata is invalid.', 502, 'provider_pagination_invalid');
        }
        Cache::forget($cacheKey.':failures');

        return ['data' => $data, 'pages' => $pages === '' ? 0 : (int) $pages];
    }

    private function failure(string $key): void
    {
        Cache::add($key.':failures', 0, 300);
        if (Cache::increment($key.':failures') >= 3) {
            Cache::put($key.':paused', now()->getTimestamp() + 300, 300);
        }
    }
}
