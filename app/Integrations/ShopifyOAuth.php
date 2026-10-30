<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Integrations\Http\PinnedJsonReader;
use App\Integrations\Http\ShopifyApi;
use App\Jobs\ProvisionShopifyWebhook;
use App\Models\ShopifyAuthorization;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopifyOAuth
{
    public function __construct(private PinnedJsonReader $reader, private ConnectionManager $manager, private AuditLogger $audit) {}

    /** @return array<string,mixed> */
    public function start(Request $request, WebshopMembership $actor, string $shop): array
    {
        $this->configuration();
        if (! $request->hasSession() || ! preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/D', $shop)) {
            throw ApiException::conflict('A browser session and canonical Shopify domain are required.', 'oauth_session_required');
        }
        $c = WebshopConnection::where('webshop_id', $actor->webshop_id)->where('platform', EcommercePlatform::Shopify)
            ->where('status', '!=', ConnectionStatus::Disconnected)->first();
        if ($c === null) {
            [$c] = $this->manager->create($actor, EcommercePlatform::Shopify,
                ['shop_domain' => $shop, 'signing_secret' => (string) config('integrations.shopify_oauth.client_secret')]);
        }
        if ($c->external_shop_ref !== $shop) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
        $state = bin2hex(random_bytes(32));
        ShopifyAuthorization::create(['connection_id' => $c->id, 'user_id' => $actor->user_id, 'state_hash' => hash('sha256', $state),
            'session_hash' => $this->sessionHash($request), 'config_hash' => $this->configHash(),
            'credential_generation' => $c->credential_generation, 'credential_hash' => $this->credentialHash($c), 'expires_at' => now()->addMinutes(10)]);
        $url = 'https://'.$shop.'/admin/oauth/authorize?'.http_build_query(['client_id' => config('integrations.shopify_oauth.client_id'),
            'scope' => implode(',', config('integrations.shopify_oauth.scopes')), 'redirect_uri' => $this->redirectUri(), 'state' => $state], '', '&', PHP_QUERY_RFC3986);

        return ['connection' => $c->public_id, 'authorization_url' => $url, 'expires_in' => 600];
    }

    public function complete(Request $request): WebshopConnection
    {
        $this->configuration();
        $params = $request->query();
        $seen = [];
        foreach (explode('&', (string) $request->server('QUERY_STRING')) as $entry) {
            $name = urldecode(explode('=', $entry, 2)[0]);
            if (isset($seen[$name])) {
                throw new ApiException('The authorization callback could not be verified.', 401, 'oauth_callback_rejected');
            }
            $seen[$name] = true;
        }
        foreach ($params as $key => $value) {
            if (! is_string($key) || ! preg_match('/\A[a-z_]+\z/D', $key) || ! is_string($value) || strlen($value) > 2048) {
                throw new ApiException('The authorization callback could not be verified.', 401, 'oauth_callback_rejected');
            }
        }
        $hmac = $params['hmac'] ?? '';
        unset($params['hmac']);
        ksort($params);
        $message = implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($params), array_values($params)));
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $hmac) || ! hash_equals(hash_hmac('sha256', $message, (string) config('integrations.shopify_oauth.client_secret')), $hmac)
            || ! preg_match('/\A[0-9]{10}\z/D', $params['timestamp'] ?? '') || abs(now()->getTimestamp() - (int) $params['timestamp']) > 300
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $params['state'] ?? '') || ! preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/D', $params['shop'] ?? '')
            || ! preg_match('/\A[A-Za-z0-9_-]{1,1024}\z/D', $params['code'] ?? '')) {
            throw new ApiException('The authorization callback could not be verified.', 401, 'oauth_callback_rejected');
        }
        $candidate = ShopifyAuthorization::where('state_hash', hash('sha256', $params['state']))->first();
        abort_if($candidate === null, 404);
        [$state, $snapshot] = DB::transaction(function () use ($candidate, $request, $params) {
            $c = WebshopConnection::with('webshop')->whereKey($candidate->connection_id)->lockForUpdate()->firstOrFail();
            $s = ShopifyAuthorization::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $this->authorizeActor($request, $c, $s);
            if ($s->used_at !== null || $s->expires_at->isPast() || $s->credential_generation !== $c->credential_generation
                || ! hash_equals($s->credential_hash, $this->credentialHash($c)) || ! hash_equals($s->config_hash, $this->configHash())
                || $c->status === ConnectionStatus::Disconnected || $c->external_shop_ref !== $params['shop']) {
                throw ApiException::conflict('This authorization request is no longer valid. Start again.', 'oauth_state_expired');
            }
            $s->forceFill(['used_at' => now(), 'status' => 'exchanging'])->save();

            return [$s, $c];
        });
        try {
            $pair = $this->exchange($snapshot, ['client_id' => config('integrations.shopify_oauth.client_id'),
                'client_secret' => config('integrations.shopify_oauth.client_secret'), 'code' => $params['code'], 'expiring' => '1']);
            $provisional = clone $snapshot;
            $provisional->credentials = ['signing_secret' => (string) config('integrations.shopify_oauth.client_secret'), 'oauth_config_hash' => $this->configHash()] + $pair;
            app(ShopifyApi::class)->test($provisional);

            return DB::transaction(function () use ($snapshot, $state, $pair, $request) {
                $c = WebshopConnection::with('webshop')->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                $s = ShopifyAuthorization::whereKey($state->id)->lockForUpdate()->firstOrFail();
                $this->authorizeActor($request, $c, $s);
                if (! $snapshot->hasSameCredentialAuthority($c) || $c->status === ConnectionStatus::Disconnected || $s->config_hash !== $this->configHash()) {
                    throw ApiException::conflict('The connection changed during the API test. Retry with its current configuration.', 'connection_changed');
                }
                $this->manager->replaceCredentials($c, ['shop_domain' => $c->external_shop_ref,
                    'signing_secret' => (string) config('integrations.shopify_oauth.client_secret'), 'access_token' => $pair['access_token']]);
                $c->credentials = ($c->credentials ?? []) + $pair + ['oauth_authority' => (string) Str::uuid(), 'oauth_config_hash' => $this->configHash()];
                $c->save();
                $s->forceFill(['status' => 'succeeded'])->save();
                if (config('integrations.shopify_webhooks.enabled')) {
                    $connectionId = $c->id;
                    $generation = $c->credential_generation;
                    $webshopId = $c->webshop_id;
                    DB::afterCommit(function () use ($connectionId, $generation, $webshopId) {
                        foreach (ShopifyApi::WEBHOOK_TOPICS as $topic) {
                            try {
                                if (config('queue.default') === 'sync') {
                                    throw new ApiException('Configure an asynchronous queue for automatic webhook provisioning.', 503, 'webhook_async_queue_required');
                                }
                                ProvisionShopifyWebhook::dispatch($connectionId, $generation, $topic);
                            } catch (\Throwable $e) {
                                // Setup failures must not relabel a committed OAuth grant as failed.
                                $this->audit->recordSafely('integration.webhook_dispatch_failed', category: AuditCategory::Integration,
                                    resource: WebshopConnection::class, resourceId: $connectionId, webshopId: $webshopId,
                                    metadata: ['generation' => $generation, 'topic' => $topic, 'failure' => $e instanceof ApiException ? $e->errorCode : 'dispatch_failed']);
                                report($e);
                            }
                        }
                    });
                }
                $this->audit->record('integration.oauth_authorized', category: AuditCategory::Integration, resource: $c, webshopId: $c->webshop_id);

                return $c;
            });
        } catch (\Throwable $e) {
            ShopifyAuthorization::whereKey($state->id)->update(['status' => 'failed']);
            throw $e;
        }
    }

    public function accessToken(WebshopConnection $model): ?string
    {
        $expiry = $model->credential('access_token_expires_at');
        if ($expiry === null) {
            return $model->credential('access_token');
        }
        $this->configuration();
        if ($model->credential('oauth_config_hash') !== $this->configHash()) {
            $this->invalidate($model);
        }
        if ((int) $expiry > now()->getTimestamp() + 120) {
            return $model->credential('access_token');
        }
        $lock = Cache::lock('shopify-refresh:'.$model->public_id, 55);
        if (! $lock->get()) {
            throw new ApiException('Token renewal is in progress. Retry shortly.', 503, 'oauth_refresh_in_progress');
        }
        try {
            $snapshot = $model->fresh();
            if ($snapshot === null || $snapshot->status === ConnectionStatus::Disconnected) {
                throw ApiException::conflict('This connection is disconnected. Create a new one.', 'connection_disconnected');
            }
            if ((int) $snapshot->credential('access_token_expires_at') > now()->getTimestamp() + 120) {
                $model->refresh();

                return $model->credential('access_token');
            }
            if ($snapshot->credential('oauth_config_hash') !== $this->configHash() || (int) $snapshot->credential('refresh_token_expires_at') <= now()->getTimestamp()) {
                $this->invalidate($snapshot);
            }
            try {
                $pair = $this->exchange($snapshot, ['client_id' => config('integrations.shopify_oauth.client_id'),
                    'client_secret' => config('integrations.shopify_oauth.client_secret'), 'grant_type' => 'refresh_token',
                    'refresh_token' => $snapshot->credential('refresh_token')]);
            } catch (ApiException $e) {
                if (in_array($e->errorCode, ['provider_authentication_failed', 'provider_response_invalid', 'provider_scope_missing'], true)) {
                    $this->invalidate($snapshot);
                }
                throw $e;
            }
            if ($pair['oauth_scopes'] !== $snapshot->credential('oauth_scopes')) {
                $this->invalidate($snapshot);
            }
            DB::transaction(function () use ($snapshot, $pair) {
                $c = WebshopConnection::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                if (! $snapshot->hasSameCredentialAuthority($c) || $snapshot->getRawOriginal('credentials') !== $c->getRawOriginal('credentials') || $c->status === ConnectionStatus::Disconnected) {
                    throw ApiException::conflict('The connection changed during the API test. Retry with its current configuration.', 'connection_changed');
                }
                $c->credentials = array_replace($c->credentials ?? [], $pair);
                $c->save();
                $this->audit->record('integration.oauth_refreshed', category: AuditCategory::Integration, resource: $c, webshopId: $c->webshop_id);
            });
            $model->refresh();

            return $model->credential('access_token');
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,mixed> $body
     * @return array<string,string>
     */
    private function exchange(WebshopConnection $c, #[\SensitiveParameter] array $body): array
    {
        if (! is_string($c->external_shop_ref) || ! preg_match('/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/D', $c->external_shop_ref)) {
            throw ApiException::conflict('The provider store identity does not match this connection.', 'provider_store_mismatch');
        }
        $data = $this->reader->read($c, 'https://'.$c->external_shop_ref.'/admin/oauth/access_token', [], 'POST', $body, true)['data'];
        foreach (['access_token', 'refresh_token'] as $key) {
            if (! is_string($data[$key] ?? null) || ! preg_match('/\A[A-Za-z0-9_-]{16,2048}\z/D', $data[$key])) {
                throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
            }
        }
        foreach (['expires_in', 'refresh_token_expires_in'] as $key) {
            if (! is_int($data[$key] ?? null) || $data[$key] < 1 || $data[$key] > 31536000) {
                throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
            }
        }
        $scopes = is_string($data['scope'] ?? null) ? array_values(array_unique(array_map('trim', explode(',', $data['scope'])))) : [];
        sort($scopes);
        foreach (config('integrations.shopify_oauth.scopes') as $required) {
            if (! in_array($required, $scopes, true)) {
                throw ApiException::conflict('The provider has not proved the required read scopes.', 'provider_scope_missing');
            }
        }

        return ['access_token' => $data['access_token'], 'refresh_token' => $data['refresh_token'],
            'access_token_expires_at' => (string) (now()->getTimestamp() + $data['expires_in']),
            'refresh_token_expires_at' => (string) (now()->getTimestamp() + $data['refresh_token_expires_in']), 'oauth_scopes' => implode(',', $scopes)];
    }

    private function authorizeActor(Request $request, WebshopConnection $c, ShopifyAuthorization $state): void
    {
        $member = WebshopMembership::where('webshop_id', $c->webshop_id)->where('user_id', $request->user()?->id)->first();
        abort_unless($state->user_id === $request->user()?->id && $member?->allows(WorkspacePermission::IntegrationsManage)
            && $request->hasSession() && hash_equals($state->session_hash, $this->sessionHash($request)), 403);
    }

    private function invalidate(WebshopConnection $snapshot): never
    {
        DB::transaction(function () use ($snapshot) {
            $c = WebshopConnection::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($snapshot->getRawOriginal('credentials') === $c->getRawOriginal('credentials') && $c->status !== ConnectionStatus::Disconnected) {
                $this->manager->replaceCredentials($c, ['shop_domain' => $c->external_shop_ref, 'signing_secret' => (string) config('integrations.shopify_oauth.client_secret')]);
                $c->forceFill(['last_error_code' => 'oauth_reauthorization_required', 'last_error_at' => now()])->save();
            }
        });
        throw ApiException::conflict('Authorize this app again to renew access.', 'oauth_reauthorization_required');
    }

    private function sessionHash(Request $r): string
    {
        return hash_hmac('sha256', $r->session()->getId(), (string) config('app.key'));
    }

    private function credentialHash(WebshopConnection $c): string
    {
        return hash_hmac('sha256', (string) $c->getRawOriginal('credentials'), (string) config('app.key'));
    }

    private function configHash(): string
    {
        return hash_hmac('sha256', json_encode(config('integrations.shopify_oauth'), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/integrations/shopify/oauth/callback';
    }

    private function configuration(): void
    {
        if (! config('integrations.shopify_oauth.enabled') || ! is_string(config('integrations.shopify_oauth.client_id'))
            || config('integrations.shopify_oauth.client_id') === '' || strlen((string) config('integrations.shopify_oauth.client_secret')) < 16
            || (! app()->environment(['local', 'testing']) && ! str_starts_with($this->redirectUri(), 'https://'))) {
            throw new ApiException('This authorization provider is not configured.', 503, 'oauth_not_configured');
        }
    }
}
