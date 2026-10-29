<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Integrations\Http\PinnedJsonReader;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wix OAuth app-instance tokens (client_credentials: app ID + secret + instance ID → 4-hour token, no
 * refresh token). App-managed connections store only `wix_app_id`; tokens are minted on demand and kept
 * encrypted with their expiry. Manually supplied tokens (app mode off) are used as-is.
 */
class WixAppTokens
{
    private const TOKEN_URL = 'https://www.wixapis.com/oauth2/token';

    public function __construct(private PinnedJsonReader $reader, private AuditLogger $audit) {}

    public static function enabled(): bool
    {
        return (bool) config('integrations.wix_app.enabled');
    }

    public function accessToken(WebshopConnection $model): string
    {
        $appId = $model->credential('wix_app_id');
        if ($appId === null) {
            $token = $model->credential('access_token');
            if ($token === null) {
                throw ApiException::conflict('Configure read-only provider credentials before testing the API.', 'provider_credentials_missing');
            }

            return $token;
        }
        $this->configuration($appId);
        if ((int) $model->credential('access_token_expires_at') > now()->getTimestamp() + 300) {
            return (string) $model->credential('access_token');
        }
        $lock = Cache::lock('wix-token:'.$model->public_id, 55);
        if (! $lock->get()) {
            throw new ApiException('Token renewal is in progress. Retry shortly.', 503, 'oauth_refresh_in_progress');
        }
        try {
            $snapshot = $model->fresh();
            if ($snapshot === null || $snapshot->status === ConnectionStatus::Disconnected) {
                throw ApiException::conflict('This connection is disconnected. Create a new one.', 'connection_disconnected');
            }
            if ((int) $snapshot->credential('access_token_expires_at') > now()->getTimestamp() + 300) {
                $model->refresh();

                return (string) $model->credential('access_token');
            }
            $minted = $this->mint($snapshot);
            DB::transaction(function () use ($snapshot, $minted) {
                $c = WebshopConnection::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                if ($c->getRawOriginal('credentials') !== $snapshot->getRawOriginal('credentials')
                    || $c->credential_generation !== $snapshot->credential_generation || $c->status === ConnectionStatus::Disconnected) {
                    throw ApiException::conflict('The connection changed during token renewal. Retry with its current configuration.', 'connection_changed');
                }
                $c->credentials = array_replace($c->credentials ?? [], $minted);
                $c->save();
                $this->audit->record('integration.oauth_refreshed', category: AuditCategory::Integration, resource: $c, webshopId: $c->webshop_id);
            });
            $model->refresh();

            return (string) $model->credential('access_token');
        } finally {
            $lock->release();
        }
    }

    /** @return array{access_token:string,access_token_expires_at:string} */
    private function mint(WebshopConnection $c): array
    {
        $instance = (string) $c->external_shop_ref;
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $instance)) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
        $data = $this->reader->read($c, self::TOKEN_URL, [], 'POST', ['grant_type' => 'client_credentials',
            'client_id' => config('integrations.wix_app.app_id'), 'client_secret' => config('integrations.wix_app.app_secret'), 'instance_id' => $instance])['data'];
        $token = $data['access_token'] ?? null;
        $ttl = $data['expires_in'] ?? null;
        if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9_.-]{16,4096}\z/D', $token) || ! is_int($ttl) || $ttl < 600 || $ttl > 86400) {
            throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
        }

        return ['access_token' => $token, 'access_token_expires_at' => (string) (now()->getTimestamp() + $ttl)];
    }

    private function configuration(string $boundAppId): void
    {
        $id = config('integrations.wix_app.app_id');
        $secret = config('integrations.wix_app.app_secret');
        if (! self::enabled() || ! is_string($id) || $id === '' || ! is_string($secret) || strlen($secret) < 16) {
            throw new ApiException('Wix app token renewal is not configured.', 503, 'wix_app_not_configured');
        }
        if (! hash_equals($boundAppId, $id)) {
            throw ApiException::conflict('This connection was authorized for a different Wix app. Reconnect it.', 'wix_app_changed');
        }
    }
}
