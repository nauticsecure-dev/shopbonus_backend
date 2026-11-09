<?php

namespace App\Integrations\Http;

use App\Exceptions\ApiException;
use App\Models\WebshopConnection;
use App\Support\ExactJson;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ecwid app storage, reserved `public` key (https://docs.ecwid.com/api-reference/rest-api/application/):
 * GET /storage/public -> [{key,value}] (read_store_profile); POST {"value":string} -> {"success":bool}
 * and DELETE -> {"success":bool} (update_store_profile). Storage is scoped to the token's app, so the
 * connection token must belong to the registered ShopBonus app. Fixed host, bounded body, no redirects, no retries.
 */
class EcwidAppStorage
{
    private const MAX_VALUE = 262144; // documented public-key limit (256 KB)

    /** @return string|null the stored value; null means confirmed absent (404 or empty list) */
    public function get(WebshopConnection $connection): ?string
    {
        $response = $this->send($connection, 'GET');
        if ($response === null) {
            return null;
        }
        $entry = array_is_list($response) ? (count($response) === 1 ? $response[0] : ($response === [] ? null : false)) : $response;
        if ($entry === null) {
            return null;
        }
        if (! is_array($entry) || ($entry['key'] ?? null) !== 'public' || ! is_string($entry['value'] ?? null)) {
            throw $this->unconfirmed();
        }

        return $entry['value'];
    }

    public function put(WebshopConnection $connection, string $value): void
    {
        if (strlen($value) > self::MAX_VALUE) {
            throw new \InvalidArgumentException('Ecwid public config exceeds 256 KB.');
        }
        $this->success($this->send($connection, 'POST', $value));
    }

    public function delete(WebshopConnection $connection): void
    {
        $response = $this->send($connection, 'DELETE');
        if ($response !== null) {
            $this->success($response);
        }
    }

    /** @param array<mixed>|null $response */
    private function success(?array $response): void
    {
        if (($response['success'] ?? null) !== true) {
            throw $this->unconfirmed();
        }
    }

    /** @return array<mixed>|null null means 404 */
    private function send(WebshopConnection $connection, string $method, ?string $value = null): ?array
    {
        $store = (string) $connection->external_shop_ref;
        $token = $connection->credential('access_token');
        if (! preg_match('/\A[1-9][0-9]{0,18}\z/D', $store) || $token === null) {
            throw ApiException::conflict('Ecwid app credentials are unavailable.', 'provider_credentials_missing');
        }
        if ($connection->paused_at !== null || ! $connection->status->receivesEvents()) {
            throw ApiException::conflict('The native connection is paused or disconnected.', 'connection_changed');
        }
        if ($method !== 'GET' && ! config('integrations.ecwid_banners.enabled')) {
            throw ApiException::conflict('Native banner writes are disabled.', 'native_banner_disabled');
        }
        try {
            $response = Http::acceptJson()->withToken((string) $token)
                ->withOptions(['allow_redirects' => false, 'proxy' => '', 'verify' => true, 'connect_timeout' => 3, 'timeout' => 8, 'stream' => true])
                ->send($method, 'https://app.ecwid.com/api/v3/'.$store.'/storage/public', $method === 'POST' ? ['json' => ['value' => $value]] : []);
        } catch (Throwable) {
            throw $this->unconfirmed();
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw ApiException::conflict('The token must belong to the installed ShopBonus Ecwid app with storage and customize_storefront scopes.', 'native_banner_permission_missing');
        }
        if ($response->status() === 404 && $method !== 'POST') {
            return null;
        }
        if ($response->status() !== 200) {
            throw $this->unconfirmed();
        }
        try {
            $stream = $response->toPsrResponse()->getBody();
            $bytes = '';
            while (! $stream->eof() && strlen($bytes) <= 2 * self::MAX_VALUE) {
                $part = $stream->read(65536);
                if ($part === '') {
                    break;
                }
                $bytes .= $part;
            }
            if (strlen($bytes) > 2 * self::MAX_VALUE || ! $stream->eof()) {
                throw new \RuntimeException;
            }
            $body = ExactJson::decode($bytes);
        } catch (Throwable) {
            throw $this->unconfirmed();
        }
        if (! is_array($body)) {
            throw $this->unconfirmed();
        }

        return $body;
    }

    private function unconfirmed(): ApiException
    {
        return new ApiException('The Ecwid app storage operation was not confirmed. Reconcile before another write.', 502, 'native_banner_unconfirmed');
    }
}
