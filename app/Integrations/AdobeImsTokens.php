<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Http\PinnedJsonReader;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Onboarding\DomainName;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** SaaS only. IMS client-credentials tokens renew without changing the underlying merchant grant. */
class AdobeImsTokens
{
    public const SCOPES = 'openid,AdobeID,email,profile,additional_info.roles,additional_info.projectedProductContext,commerce.accs';

    private const URL = 'https://ims-na1.adobelogin.com/ims/token/v3';

    public function __construct(private readonly PinnedJsonReader $reader, private readonly AuditLogger $audit) {}

    public function accessToken(WebshopConnection $model): string
    {
        $fresh = $model->fresh() ?? throw ApiException::conflict('Connection no longer exists.', 'connection_changed');
        if ($model->isLifecycleProbe()) {
            $fresh = $fresh->forLifecycleProbe();
        }
        $this->current($fresh);
        if (! $model->hasSameCredentialAuthority($fresh)) {
            throw ApiException::conflict('Connection changed; retry with current configuration.', 'connection_changed');
        }
        $model->setRawAttributes($fresh->getAttributes(), true);
        if ($model->credential('ims_client_id') === null) {
            return $model->credential('access_token') ?? throw ApiException::conflict('Configure provider credentials.', 'provider_credentials_missing');
        }
        if ($this->valid($model)) {
            return (string) $model->credential('access_token');
        }
        $lock = Cache::lock('adobe-ims:'.$model->public_id, 55);
        if (! $lock->get()) {
            throw new ApiException('Token renewal is in progress. Retry shortly.', 503, 'oauth_refresh_in_progress');
        }
        try {
            $snapshot = $model->fresh() ?? throw ApiException::conflict('Connection no longer exists.', 'connection_changed');
            if ($model->isLifecycleProbe()) {
                $snapshot = $snapshot->forLifecycleProbe();
            }
            $this->current($snapshot);
            if (! $model->hasSameCredentialAuthority($snapshot)) {
                throw ApiException::conflict('Connection changed; retry with current configuration.', 'connection_changed');
            }
            if ($this->valid($snapshot)) {
                $model->refresh();

                return (string) $model->credential('access_token');
            }
            $start = now()->getTimestamp();
            $identity = hash_hmac('sha256', json_encode([$snapshot->credential_generation, $snapshot->credential('ims_client_id'), $snapshot->credential('ims_client_secret')], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $data = $this->reader->read($snapshot, self::URL, [], 'POST', ['grant_type' => 'client_credentials', 'client_id' => $snapshot->credential('ims_client_id'), 'client_secret' => $snapshot->credential('ims_client_secret'), 'scope' => self::SCOPES], true, credentialIdentity: $identity)['data'];
            $token = $data['access_token'] ?? null;
            $ttl = $data['expires_in'] ?? null;
            if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9_.-]{16,4096}\z/D', $token) || ! is_int($ttl) || $ttl < 600 || $ttl > 86400
                || ! is_string($data['token_type'] ?? null) || strtolower($data['token_type']) !== 'bearer') {
                throw new ApiException('The provider response could not be read.', 502, 'provider_response_invalid');
            }
            DB::transaction(function () use ($snapshot, $token, $ttl, $start) {
                $c = WebshopConnection::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                if ($snapshot->isLifecycleProbe()) {
                    $c = $c->forLifecycleProbe();
                }
                $this->current($c);
                if ($c->getRawOriginal('credentials') !== $snapshot->getRawOriginal('credentials') || $c->credential_generation !== $snapshot->credential_generation || $c->external_shop_ref !== $snapshot->external_shop_ref) {
                    throw ApiException::conflict('Connection changed during renewal.', 'connection_changed');
                }
                $c->credentials = array_replace($c->credentials ?? [], ['access_token' => $token, 'access_token_expires_at' => (string) ($start + $ttl)]);
                $c->save();
                $this->audit->record('integration.oauth_refreshed', category: AuditCategory::Integration, resource: $c, webshopId: $c->webshop_id, metadata: ['provider' => 'adobe_ims']);
            });
            $model->refresh();

            return (string) $model->credential('access_token');
        } finally {
            $lock->release();
        }
    }

    private function valid(WebshopConnection $c): bool
    {
        return $c->credential('access_token') !== null && (int) $c->credential('access_token_expires_at') > now()->getTimestamp() + 300;
    }

    private function current(WebshopConnection $c): void
    {
        if (! $c->status->receivesEvents()) {
            throw ApiException::conflict('This connection is disconnected.', 'connection_disconnected');
        }
        if ($c->paused_at !== null && ! $c->isLifecycleProbe()) {
            throw ApiException::conflict('This integration is paused.', 'connection_paused');
        }
        $api = $c->credential('saas_api_url');
        $base = $c->credential('store_url');
        $code = $c->credential('store_code');
        $id = $c->credential('store_id');
        if (! is_string($api) || ! preg_match('~\Ahttps://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.api\.commerce\.adobe\.com/[A-Za-z0-9_-]{1,100}\z~D', $api)
            || $base === null || $code === null || $code === 'all' || ! preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $code) || $id === null || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', $id)
            || $c->external_shop_ref !== $api.'#store='.$id.':'.$code || ! $c->webshop->isOwnershipVerified() || DomainName::fromInput($base) !== strtolower((string) $c->webshop->verified_domain)) {
            throw ApiException::conflict('Verify the exact Adobe tenant and storefront identity.', 'store_identity_unverified');
        }
        if ($c->platform !== EcommercePlatform::Magento || $c->credential('deployment') !== 'saas' || ($c->credential('ims_client_id') !== null && $c->credential('ims_client_secret') === null)) {
            throw ApiException::conflict('Configure scoped Adobe SaaS credentials.', 'provider_credentials_missing');
        }
    }
}
