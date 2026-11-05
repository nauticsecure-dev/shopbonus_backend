<?php

namespace App\Integrations\Http;

use App\Exceptions\ApiException;
use App\Integrations\Connectors\LightspeedCConnector;
use App\Models\WebshopConnection;
use App\Support\ExactJson;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Lightspeed C-Series Shop Scripts (https://developers.lightspeedhq.com/ecom/endpoints/shopscript/):
 * POST /shop/scripts.json, GET /shop/scripts/{id}.json (wrapper `shopScript`), DELETE (204).
 * Fixed cluster origin, bounded body, no redirects; no write is retried automatically.
 */
class LightspeedScripts
{
    /**
     * @param  array{body: string, location: string}|array{}  $script
     * @return array<string,mixed>|null the `shopScript` object; null means a confirmed GET 404 or DELETE 204
     */
    public function request(WebshopConnection $connection, string $method, ?int $id = null, array $script = []): ?array
    {
        if (! in_array($method, ['GET', 'POST', 'DELETE'], true) || ($method === 'POST') !== ($id === null) || ($method === 'POST' && $script === [])) {
            throw new \InvalidArgumentException('Invalid shop script operation.');
        }
        $cluster = $connection->credential('cluster');
        $language = $connection->credential('language');
        $key = $connection->credential('api_key');
        $secret = $connection->credential('api_secret');
        if (! is_string($cluster) || ! isset(LightspeedCConnector::CLUSTERS[$cluster]) || ! is_string($language) || ! preg_match('/\A[a-z]{2}\z/D', $language) || $key === null || $secret === null) {
            throw ApiException::conflict('Lightspeed API credentials are unavailable.', 'provider_credentials_missing');
        }
        if ($connection->paused_at !== null || ! $connection->status->receivesEvents()) {
            throw ApiException::conflict('The native connection is paused or disconnected.', 'connection_changed');
        }
        if ($method !== 'GET' && ! config('integrations.lightspeed_banners.enabled')) {
            throw ApiException::conflict('Native banner writes are disabled.', 'native_banner_disabled');
        }
        $url = LightspeedCConnector::CLUSTERS[$cluster].'/'.$language.'/shop/scripts'.($id === null ? '' : '/'.$id).'.json';
        try {
            $response = Http::acceptJson()->withBasicAuth((string) $key, (string) $secret)
                ->withOptions(['allow_redirects' => false, 'proxy' => '', 'verify' => true, 'connect_timeout' => 3, 'timeout' => 8, 'stream' => true])
                ->send($method, $url, $method === 'POST' ? ['json' => ['shopScript' => $script]] : []);
        } catch (Throwable) {
            throw $this->unconfirmed();
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw ApiException::conflict('The Lightspeed API key may not manage shop scripts. Grant that permission, then reconcile.', 'native_banner_permission_missing');
        }
        if (($method === 'GET' && $response->status() === 404) || ($method === 'DELETE' && $response->status() === 204)) {
            return null;
        }
        if (! in_array($response->status(), [200, 201], true)) {
            throw $this->unconfirmed();
        }
        try {
            $stream = $response->toPsrResponse()->getBody();
            $bytes = '';
            while (! $stream->eof() && strlen($bytes) <= 262144) {
                $part = $stream->read(min(65536, 262145 - strlen($bytes)));
                if ($part === '') {
                    break;
                }
                $bytes .= $part;
            }
            if (strlen($bytes) > 262144 || ! $stream->eof()) {
                throw new \RuntimeException;
            }
            $body = ExactJson::decode($bytes);
        } catch (Throwable) {
            throw $this->unconfirmed();
        }
        $object = is_array($body) ? ($body['shopScript'] ?? null) : null;
        if (! is_array($object) || array_is_list($object) || ($method === 'GET' && ($object['id'] ?? null) !== $id)) {
            throw $this->unconfirmed();
        }

        return $object;
    }

    private function unconfirmed(): ApiException
    {
        return new ApiException('The shop script operation was not confirmed. Reconcile before another write.', 502, 'native_banner_unconfirmed');
    }
}
