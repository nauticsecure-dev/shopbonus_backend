<?php

namespace App\Integrations\Http;

use App\Exceptions\ApiException;
use App\Models\WebshopConnection;
use App\Support\ExactJson;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Fixed-origin, bounded content operations. No native write is retried automatically. */
class BigCommerceWidgets
{
    /** @return list<string> */
    public function regions(WebshopConnection $connection, string $template): array
    {
        $data = app(PinnedJsonReader::class)->read($connection, 'https://api.bigcommerce.com/stores/'.$connection->external_shop_ref.'/v3/content/regions',
            ['headers' => ['X-Auth-Token' => $connection->credential('access_token')]], 'GET', ['template_file' => $template])['data']['data'] ?? null;
        if (! is_array($data) || ! array_is_list($data) || count($data) > 500) {
            throw new ApiException('Native theme regions could not be read.', 502, 'native_banner_region_invalid');
        }
        $out = [];
        foreach ($data as $region) {
            if (! is_array($region) || ! is_string($region['name'] ?? null) || strlen($region['name']) > 100) {
                throw new ApiException('Native theme regions could not be read.', 502, 'native_banner_region_invalid');
            }
            $out[] = $region['name'];
        }

        return $out;
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>|null null means a confirmed GET404 or DELETE204
     */
    public function request(WebshopConnection $connection, string $method, string $resource, ?string $id = null, array $data = []): ?array
    {
        if (! in_array($resource, ['widget-templates', 'widgets', 'placements'], true) || ! in_array($method, ['GET', 'POST', 'DELETE'], true)
            || ($id !== null && ! Str::isUuid($id)) || ($method === 'DELETE' && $id === null) || ($method === 'POST' && $id !== null)) {
            throw new \InvalidArgumentException('Invalid native content operation.');
        }
        $store = $connection->external_shop_ref;
        $token = $connection->credential('access_token');
        if (! is_string($store) || ! preg_match('/\A[a-z0-9]{3,32}\z/D', $store) || $token === null) {
            throw ApiException::conflict('Native content credentials are unavailable.', 'provider_credentials_missing');
        }
        if ($connection->paused_at !== null || ! $connection->status->receivesEvents()) {
            throw ApiException::conflict('The native connection is paused or disconnected.', 'connection_changed');
        }
        if ($method !== 'GET' && ! config('integrations.bigcommerce_banners.enabled')) {
            throw ApiException::conflict('Native banner writes are disabled.', 'native_banner_disabled');
        }
        try {
            $response = Http::acceptJson()->withHeaders(['X-Auth-Token' => $token])->withOptions(['allow_redirects' => false, 'proxy' => '', 'verify' => true, 'connect_timeout' => 3, 'timeout' => 8, 'stream' => true])
                ->send($method, 'https://api.bigcommerce.com/stores/'.$store.'/v3/content/'.$resource.($id === null ? '' : '/'.$id), $method === 'GET' ? ['query' => $data] : ($method === 'POST' ? ['json' => $data] : []));
            if ($method === 'GET' && $response->status() === 404) {
                return null;
            }
            if ($method === 'DELETE' && $response->status() === 204) {
                return null;
            }
            if ($response->status() !== 200) {
                throw new \RuntimeException;
            }
            $stream = $response->toPsrResponse()->getBody();
            $bytes = '';
            while (! $stream->eof() && strlen($bytes) <= 1048576) {
                $part = $stream->read(min(65536, 1048577 - strlen($bytes)));
                if ($part === '') {
                    break;
                }$bytes .= $part;
            }
            if (strlen($bytes) > 1048576 || ! $stream->eof()) {
                throw new \RuntimeException;
            }
            $body = ExactJson::decode($bytes);
            if (! is_array($body) || ! is_array($body['data'] ?? null) || array_is_list($body['data'])
                || ($method === 'GET' && $id !== null && ($body['data']['uuid'] ?? null) !== $id)) {
                throw new \RuntimeException;
            }

            return $body['data'];
        } catch (Throwable) {
            throw new ApiException('The native content operation was not confirmed. Reconcile before another write.', 502, 'native_banner_unconfirmed');
        }
    }
}
