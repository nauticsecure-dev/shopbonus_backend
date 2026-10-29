<?php

namespace App\Integrations\Http;

use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Models\WebshopConnection;
use App\Services\Onboarding\DomainName;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\PublicAddressGuard;
use App\Support\ExactJson;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;

/** Bounded authenticated JSON reads only; Shopify POST bodies are fixed GraphQL queries. */
class PinnedJsonReader
{
    public function __construct(private HostResolver $resolver) {}

    /**
     * @param  array{basic?:array{string,string},headers?:array<string,string>}  $auth
     * @param  array<string,mixed>  $query
     * @return array{data:array<mixed>,headers:array<string,string>}
     */
    public function read(WebshopConnection $connection, string $url, #[\SensitiveParameter] array $auth, string $method = 'GET', #[\SensitiveParameter] array $query = [], bool $form = false): array
    {
        if ($connection->status === ConnectionStatus::Disconnected) {
            throw ApiException::conflict('This connection is disconnected. Create a new one.', 'connection_disconnected');
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)
            || ! in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('Invalid provider read request.');
        }
        if (! extension_loaded('curl')) {
            throw new ApiException('The secure provider transport is not available.', 503, 'provider_transport_unavailable');
        }
        $host = strtolower($parts['host'] ?? '');
        DomainName::fromInput($host);
        $cacheKey = 'commerce-api:'.hash_hmac('sha256', $connection->public_id.'|'.$host.'|'.json_encode($auth, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $pausedUntil = filter_var(Cache::get($cacheKey.':paused'), FILTER_VALIDATE_INT);
        if ($pausedUntil !== false && $pausedUntil > now()->getTimestamp()) {
            throw new ApiException('Provider reads are temporarily paused. Retry later.', 503, 'provider_circuit_open', headers: ['Retry-After' => (string) ($pausedUntil - now()->getTimestamp())]);
        }
        $addresses = $this->resolver->addresses($host);
        if (! PublicAddressGuard::allPublic($addresses)) {
            throw ApiException::conflict('The store domain must resolve only to public addresses.', 'provider_address_refused');
        }
        $pin = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
        try {
            $pending = Http::acceptJson();
            if ($form) {
                $pending->asForm();
            }
            $response = $pending->withHeaders($auth['headers'] ?? [])->withOptions([
                'allow_redirects' => false, 'proxy' => '', 'verify' => true, 'connect_timeout' => 3, 'timeout' => 8, 'stream' => true,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pin}"]],
                'auth' => $auth['basic'] ?? null,
            ])->send($method, $url, $method === 'GET' ? ['query' => $query] : [$form ? 'form_params' : 'json' => $query]);
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
            $data = ExactJson::decode($body);
        } catch (JsonException) {
            throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
        }
        if (! is_array($data)) {
            throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
        }

        Cache::forget($cacheKey.':failures');

        return ['data' => $data, 'headers' => [
            'pages' => $response->header('X-WP-TotalPages'), 'version' => $response->header('X-Shopify-API-Version'),
        ]];
    }

    /** @param array{basic?:array{string,string},headers?:array<string,string>} $auth */
    public function pause(WebshopConnection $connection, string $host, #[\SensitiveParameter] array $auth, int $seconds): void
    {
        $key = 'commerce-api:'.hash_hmac('sha256', $connection->public_id.'|'.$host.'|'.json_encode($auth, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $seconds = max(1, min(3600, $seconds));
        Cache::put($key.':paused', now()->getTimestamp() + $seconds, $seconds);
    }

    private function failure(string $key): void
    {
        Cache::add($key.':failures', 0, 300);
        if (Cache::increment($key.':failures') >= 3) {
            Cache::put($key.':paused', now()->getTimestamp() + 300, 300);
        }
    }
}
