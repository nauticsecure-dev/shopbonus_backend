<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Http\WooCommerceApi;
use App\Models\ShopwareAppShop;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Onboarding\DomainName;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shopware app-system registration handshake (formulas match shopware/app-php-sdk):
 * - GET registration: `shopware-app-signature` = hex HMAC-SHA256("shop-id=…&shop-url=…&timestamp=…", app secret);
 *   a confirmed shop must also sign with its current shop secret (`shopware-shop-signature`).
 * - Response proof = hex HMAC-SHA256(shopId . shopUrl . appName, app secret) plus a new 64-char shop secret.
 * - POST confirmation: body signed with the pending secret; re-registration also with the current one
 *   (`shopware-shop-signature-previous`).
 *
 * The returned Admin API client credentials are bound only to a Shopware connection that opted into app
 * registration with the exact same store URL on its verified domain. Native reads then still prove the
 * sales channel/domain before any order is used, so a forged registration cannot produce working reads.
 * Lock order: connection rows, then the app shop row.
 */
class ShopwareAppRegistration
{
    private const WINDOW = 600;

    public const PREVIOUS_SECRET_ALLOWANCE = 60;

    public function __construct(private AuditLogger $audit) {}

    /** @return array{proof:string,secret:string,confirmation_url:string} */
    public function register(Request $request): array
    {
        [$name, $appSecret] = $this->configuration();
        $shopId = $request->query('shop-id');
        $shopUrl = $request->query('shop-url');
        $timestamp = $request->query('timestamp');
        if (! is_string($shopId) || ! preg_match('/\A[A-Za-z0-9]{1,64}\z/D', $shopId) || ! is_string($shopUrl) || strlen($shopUrl) > 255
            || ! is_string($timestamp) || ! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > self::WINDOW) {
            throw $this->rejected('registration_parameters_invalid');
        }
        $this->verify(sprintf('shop-id=%s&shop-url=%s&timestamp=%s', $shopId, $shopUrl, $timestamp), $appSecret, $request->header('shopware-app-signature'));
        WooCommerceApi::baseUrl($shopUrl);
        $secret = Str::random(64);

        try {
            DB::transaction(function () use ($request, $shopId, $shopUrl, $timestamp, $secret) {
                $shop = ShopwareAppShop::where('shop_id', $shopId)->lockForUpdate()->first();
                if ($shop?->confirmed_at !== null) {
                    // Re-registration must also prove the shop's current secret.
                    $this->verify(sprintf('shop-id=%s&shop-url=%s&timestamp=%s', $shopId, $shopUrl, $timestamp), (string) $shop->shop_secret, $request->header('shopware-shop-signature'));
                }
                $shop ??= new ShopwareAppShop(['shop_id' => $shopId]);
                $shop->fill(['pending_shop_secret' => $secret, 'pending_shop_url' => $shopUrl])->save();
                $this->audit->record('integration.shopware_app_registration_started', category: AuditCategory::Integration, resource: $shop,
                    metadata: ['shop_id' => $shopId, 're_registration' => $shop->confirmed_at !== null]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('A registration for this shop is already in progress. Retry.', 'registration_in_progress');
        }

        return [
            'proof' => hash_hmac('sha256', $shopId.$shopUrl.$name, $appSecret),
            'secret' => $secret,
            'confirmation_url' => rtrim((string) config('app.url'), '/').'/api/v1/integrations/shopware/app/registration/confirm',
        ];
    }

    public function confirm(Request $request): void
    {
        $this->configuration();
        $body = $request->getContent();
        $p = json_decode($body, true);
        if (! is_array($p) || ! is_string($p['shopId'] ?? null) || ! preg_match('/\A[A-Za-z0-9]{1,64}\z/D', $p['shopId'])
            || ! is_string($p['shopUrl'] ?? null) || ! (is_int($p['timestamp'] ?? null) || (is_string($p['timestamp'] ?? null) && ctype_digit($p['timestamp'])))
            || abs(now()->getTimestamp() - (int) $p['timestamp']) > self::WINDOW
            || ! is_string($p['apiKey'] ?? null) || ! preg_match('/\A[A-Za-z0-9_-]{16,255}\z/D', $p['apiKey'])
            || ! is_string($p['secretKey'] ?? null) || strlen($p['secretKey']) < 16 || strlen($p['secretKey']) > 255) {
            throw $this->rejected('confirmation_parameters_invalid');
        }
        $shopPk = DB::transaction(function () use ($request, $body, $p) {
            $shop = ShopwareAppShop::where('shop_id', $p['shopId'])->lockForUpdate()->first();
            if ($shop === null || $shop->pending_shop_secret === null) {
                throw $this->rejected('signature_invalid');
            }
            $this->verify($body, $shop->pending_shop_secret, $request->header('shopware-shop-signature'));
            if ($shop->confirmed_at !== null) {
                $this->verify($body, (string) $shop->shop_secret, $request->header('shopware-shop-signature-previous'));
            }
            if (! hash_equals((string) $shop->pending_shop_url, $p['shopUrl'])) {
                throw $this->rejected('shop_url_mismatch');
            }
            $rotated = $shop->confirmed_at !== null && $shop->shop_secret !== $shop->pending_shop_secret;
            $shop->forceFill([
                'previous_shop_secret' => $rotated ? $shop->shop_secret : $shop->previous_shop_secret,
                'secrets_rotated_at' => $rotated ? now() : $shop->secrets_rotated_at,
                'shop_secret' => $shop->pending_shop_secret, 'pending_shop_secret' => null,
                'shop_url' => $shop->pending_shop_url, 'pending_shop_url' => null,
                'api_credentials' => ['api_key' => $p['apiKey'], 'secret_key' => $p['secretKey']],
                'confirmed_at' => $shop->confirmed_at ?? now(),
            ])->save();
            $this->audit->record('integration.shopware_app_registered', category: AuditCategory::Integration, resource: $shop,
                metadata: ['shop_id' => $shop->shop_id, 'secret_rotated' => $rotated]);

            return $shop->id;
        });
        $this->bindConfirmed($shopPk);
    }

    /** Called inside the create/replace transaction of an app-registration connection (row already locked). */
    public function attach(WebshopConnection $c): void
    {
        if (! $this->eligible($c)) {
            return;
        }
        $shops = ShopwareAppShop::whereNotNull('confirmed_at')->where(fn ($q) => $q->whereNull('connection_id')->orWhere('connection_id', $c->id))
            ->lockForUpdate()->get()->filter(fn (ShopwareAppShop $s) => $s->api_credentials !== null && $this->sameStore($s, $c));
        if ($shops->count() === 1) {
            $this->apply($c, $shops->first(), false);
        }
    }

    /** App webhooks: current shop secret, or the previous one within the in-flight allowance after rotation. */
    public function signedByShop(ShopwareAppShop $shop, string $body, mixed $signature): bool
    {
        if (! is_string($signature) || $shop->shop_secret === null) {
            return false;
        }
        $signature = strtolower($signature);
        if (hash_equals(hash_hmac('sha256', $body, $shop->shop_secret), $signature)) {
            return true;
        }

        return $shop->previous_shop_secret !== null && $shop->secrets_rotated_at !== null
            && $shop->secrets_rotated_at->addSeconds(self::PREVIOUS_SECRET_ALLOWANCE)->isFuture()
            && hash_equals(hash_hmac('sha256', $body, $shop->previous_shop_secret), $signature);
    }

    /** Signed app.deleted: the Admin API integration is gone, so the binding and credentials are revoked. */
    public function revoke(int $shopPk, bool $keepUserData): void
    {
        DB::transaction(function () use ($shopPk, $keepUserData) {
            $peek = ShopwareAppShop::find($shopPk);
            $c = $peek?->connection_id === null ? null : WebshopConnection::whereKey($peek->connection_id)->lockForUpdate()->first();
            $shop = ShopwareAppShop::whereKey($shopPk)->lockForUpdate()->first();
            if ($shop === null) {
                return;
            }
            if ($c !== null && $c->id === $shop->connection_id) {
                $this->release($c, $shop);
            }
            $this->audit->record('integration.shopware_app_deleted', category: AuditCategory::Integration, resource: $shop,
                metadata: ['shop_id' => $shop->shop_id, 'keep_user_data' => $keepUserData]);
            if ($keepUserData) {
                // Shopware asked to keep the shop for a later re-install; its secret still authenticates re-registration.
                $shop->forceFill(['api_credentials' => null, 'connection_id' => null])->save();
            } else {
                $shop->delete();
            }
        });
    }

    private function bindConfirmed(int $shopPk): void
    {
        DB::transaction(function () use ($shopPk) {
            $peek = ShopwareAppShop::findOrFail($shopPk);
            $host = DomainName::fromInput(WooCommerceApi::baseUrl((string) $peek->shop_url));
            $candidates = WebshopConnection::with('webshop')->where('platform', EcommercePlatform::Shopware6)
                ->where('status', '!=', ConnectionStatus::Disconnected)
                ->where(fn ($q) => $q->whereHas('webshop', fn ($w) => $w->where('verified_domain', $host))->when($peek->connection_id !== null, fn ($o) => $o->orWhere('id', $peek->connection_id)))
                ->orderBy('id')->lockForUpdate()->get();
            $shop = ShopwareAppShop::whereKey($shopPk)->lockForUpdate()->firstOrFail();
            $previous = $candidates->firstWhere('id', $shop->connection_id);
            if ($previous !== null && $this->eligible($previous) && $this->sameStore($shop, $previous)) {
                $this->apply($previous, $shop, true);

                return;
            }
            if ($previous !== null) {
                $this->release($previous, $shop);
            }
            $taken = ShopwareAppShop::whereNotNull('connection_id')->where('id', '!=', $shop->id)->pluck('connection_id')->all();
            $matches = $candidates->filter(fn (WebshopConnection $c) => $c->id !== $previous?->id && ! in_array($c->id, $taken, true)
                && $this->eligible($c) && $this->sameStore($shop, $c));
            if ($matches->count() === 1) {
                $this->apply($matches->first(), $shop, true);
            }
        });
    }

    private function eligible(WebshopConnection $c): bool
    {
        return $c->platform === EcommercePlatform::Shopware6 && $c->status !== ConnectionStatus::Disconnected
            && in_array($c->credential('app_registration'), ['pending', 'bound'], true) && $c->webshop->isOwnershipVerified();
    }

    private function sameStore(ShopwareAppShop $s, WebshopConnection $c): bool
    {
        try {
            $base = WooCommerceApi::baseUrl((string) $s->shop_url);
        } catch (ApiException) {
            return false;
        }

        return $c->credential('store_url') === $base && DomainName::fromInput($base) === strtolower((string) $c->webshop->verified_domain);
    }

    private function apply(WebshopConnection $c, ShopwareAppShop $shop, bool $newGeneration): void
    {
        $creds = (array) $shop->api_credentials;
        $c->credentials = array_replace($c->credentials ?? [], ['client_id' => $creds['api_key'], 'client_secret' => $creds['secret_key'], 'app_registration' => 'bound', 'app_shop_id' => $shop->shop_id]);
        if ($newGeneration) {
            $this->resetReadiness($c);
        }
        $c->save();
        $shop->forceFill(['connection_id' => $c->id])->save();
        $this->audit->record('integration.shopware_app_bound', category: AuditCategory::Integration, resource: $c, webshopId: $c->webshop_id,
            metadata: ['shop_id' => $shop->shop_id]);
    }

    private function release(WebshopConnection $c, ShopwareAppShop $shop): void
    {
        if ($c->credential('app_shop_id') !== $shop->shop_id) {
            // The merchant already replaced these credentials; only drop the stale link.
            $shop->forceFill(['connection_id' => null])->save();

            return;
        }
        $creds = $c->credentials ?? [];
        unset($creds['client_id'], $creds['client_secret'], $creds['app_shop_id']);
        // Store URL changed away from this connection: it waits for a matching registration again.
        $c->credentials = array_replace($creds, ['app_registration' => 'pending']);
        $this->resetReadiness($c);
        $c->save();
        $shop->forceFill(['connection_id' => null])->save();
        $this->audit->record('integration.shopware_app_unbound', category: AuditCategory::Integration, resource: $c, webshopId: $c->webshop_id,
            metadata: ['shop_id' => $shop->shop_id]);
    }

    private function resetReadiness(WebshopConnection $c): void
    {
        $c->forceFill([
            'credential_generation' => $c->credential_generation + 1,
            'status' => ConnectionStatus::SetupRequired, 'limitations' => null,
            'connected_at' => null, 'last_event_at' => null, 'last_verified_order_at' => null,
            'last_error_code' => null, 'last_error_at' => null,
        ]);
    }

    private function verify(string $message, string $secret, mixed $signature): void
    {
        if ($secret === '' || ! is_string($signature) || ! hash_equals(hash_hmac('sha256', $message, $secret), strtolower($signature))) {
            throw $this->rejected('signature_invalid');
        }
    }

    /** @return array{0:string,1:string} */
    private function configuration(): array
    {
        $name = config('integrations.shopware_app.name');
        $secret = config('integrations.shopware_app.secret');
        if (! config('integrations.shopware_app.enabled') || ! is_string($name) || $name === '' || ! is_string($secret) || strlen($secret) < 16) {
            throw new ApiException('The Shopware app is not configured.', 503, 'shopware_app_not_configured');
        }

        return [$name, $secret];
    }

    private function rejected(string $reason): ApiException
    {
        return new ApiException('The registration request could not be verified.', 401, 'registration_rejected', ['reason' => $reason]);
    }
}
