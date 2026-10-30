<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectionManager;
use App\Models\AuditEvent;
use App\Models\ShopwareAppShop;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShopwareAppRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'SYNTHETIC_shopware_app_secret';

    private const SHOP_ID = 'sHoP1a2B3c4D5e6F';

    private const SHOP_URL = 'https://fietsen-utrecht.nl';

    private const CHANNEL = '0123456789abcdef0123456789abcdef';

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.shopbonus.example', 'integrations.shopware_app' => ['enabled' => true, 'name' => 'ShopbonusApp', 'secret' => self::APP_SECRET]]);
        $u = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
    }

    private function appConnection(): WebshopConnection
    {
        return app(ConnectionManager::class)->create($this->shop->memberships()->sole(), EcommercePlatform::Shopware6, ['app_registration' => true, 'store_url' => self::SHOP_URL, 'sales_channel_id' => self::CHANNEL])[0];
    }

    /** @param array<string,string> $extra */
    private function register(string $url = self::SHOP_URL, ?int $ts = null, ?string $shopSecret = null, string $appSecret = self::APP_SECRET): TestResponse
    {
        $ts ??= now()->getTimestamp();
        $message = sprintf('shop-id=%s&shop-url=%s&timestamp=%s', self::SHOP_ID, $url, $ts);
        $headers = ['shopware-app-signature' => hash_hmac('sha256', $message, $appSecret), 'sw-version' => '6.7.0.0'];
        if ($shopSecret !== null) {
            $headers['shopware-shop-signature'] = hash_hmac('sha256', $message, $shopSecret);
        }

        return $this->withHeaders($headers)->getJson('/api/v1/integrations/shopware/app/registration?'.http_build_query(['shop-id' => self::SHOP_ID, 'shop-url' => $url, 'timestamp' => $ts]));
    }

    private function confirm(string $secret, ?string $previous = null, string $url = self::SHOP_URL, string $apiKey = 'SWIASYNTHETICAPIKEY01'): TestResponse
    {
        $body = json_encode(['apiKey' => $apiKey, 'secretKey' => 'SYNTHETIC_secret_key_value_0001', 'timestamp' => (string) now()->getTimestamp(), 'shopUrl' => $url, 'shopId' => self::SHOP_ID]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_SHOPWARE_SHOP_SIGNATURE' => hash_hmac('sha256', $body, $secret)];
        if ($previous !== null) {
            $server['HTTP_SHOPWARE_SHOP_SIGNATURE_PREVIOUS'] = hash_hmac('sha256', $body, $previous);
        }

        return $this->call('POST', '/api/v1/integrations/shopware/app/registration/confirm', [], [], [], $server, $body);
    }

    public function test_handshake_returns_sdk_compatible_proof_and_binds_the_matching_app_connection(): void
    {
        $c = $this->appConnection();
        $this->assertNull($c->credential('client_id'));
        $generation = $c->credential_generation;

        $r = $this->register()->assertOk();
        $this->assertSame(hash_hmac('sha256', self::SHOP_ID.self::SHOP_URL.'ShopbonusApp', self::APP_SECRET), $r->json('proof'));
        $this->assertSame('https://api.shopbonus.example/api/v1/integrations/shopware/app/registration/confirm', $r->json('confirmation_url'));
        $this->assertSame(64, strlen($r->json('secret')));
        $this->assertNull($r->json('data'));

        $this->confirm($r->json('secret'))->assertNoContent();
        $c->refresh();
        $this->assertSame('SWIASYNTHETICAPIKEY01', $c->credential('client_id'));
        $this->assertSame('bound', $c->credential('app_registration'));
        $this->assertSame(self::SHOP_ID, $c->credential('app_shop_id'));
        $this->assertSame($generation + 1, $c->credential_generation);
        $this->assertSame('setup_required', $c->status->value);
        $s = ShopwareAppShop::sole();
        $this->assertSame($c->id, $s->connection_id);
        $this->assertSame($r->json('secret'), $s->shop_secret);
        $this->assertNull($s->pending_shop_secret);
        $this->assertStringNotContainsString('SYNTHETIC_secret_key_value_0001', AuditEvent::pluck('metadata')->toJson());
    }

    public function test_app_installed_before_connection_binds_when_merchant_creates_it(): void
    {
        $this->confirm($this->register()->json('secret'))->assertNoContent();
        $this->assertNull(ShopwareAppShop::sole()->connection_id);
        $c = $this->appConnection();
        $this->assertSame('bound', $c->fresh()->credential('app_registration'));
        $this->assertSame('SWIASYNTHETICAPIKEY01', $c->fresh()->credential('client_id'));
        $this->assertSame($c->id, ShopwareAppShop::sole()->connection_id);
    }

    public function test_forged_stale_or_disabled_registration_stores_nothing(): void
    {
        $this->register(appSecret: 'SYNTHETIC_wrong_app_secret')->assertStatus(401)->assertJsonPath('error.details.reason', 'signature_invalid');
        $this->register(ts: now()->getTimestamp() - 3600)->assertStatus(401)->assertJsonPath('error.details.reason', 'registration_parameters_invalid');
        $this->register(url: 'http://fietsen-utrecht.nl')->assertStatus(422);
        config(['integrations.shopware_app.enabled' => false]);
        $this->register()->assertStatus(503)->assertJsonPath('error.code', 'shopware_app_not_configured');
        $this->assertDatabaseCount('shopware_app_shops', 0);
    }

    public function test_confirmation_requires_pending_secret_signature_and_same_url(): void
    {
        $c = $this->appConnection();
        $secret = $this->register()->json('secret');
        $this->confirm('SYNTHETIC_not_the_pending_secret_value')->assertStatus(401);
        $this->confirm($secret, url: 'https://other.example')->assertStatus(401)->assertJsonPath('error.details.reason', 'shop_url_mismatch');
        $this->assertNull(ShopwareAppShop::sole()->confirmed_at);
        $this->assertNull($c->fresh()->credential('client_id'));
        $this->confirm($secret)->assertNoContent();
        // The pending secret is consumed: replaying the same confirmation cannot run again.
        $this->confirm($secret)->assertStatus(401);
    }

    public function test_re_registration_needs_both_signatures_and_rotates_secret(): void
    {
        $c = $this->appConnection();
        $first = $this->register()->json('secret');
        $this->confirm($first)->assertNoContent();

        $this->register()->assertStatus(401);
        $second = $this->register(shopSecret: $first)->assertOk()->json('secret');
        $this->assertSame($first, ShopwareAppShop::sole()->shop_secret, 'Current secret stays valid until confirmation.');
        $this->confirm($second, apiKey: 'SWIASYNTHETICAPIKEY02')->assertStatus(401);
        $this->confirm($second, $first, apiKey: 'SWIASYNTHETICAPIKEY02')->assertNoContent();
        $s = ShopwareAppShop::sole();
        $this->assertSame($second, $s->shop_secret);
        $this->assertSame($first, $s->previous_shop_secret);
        $this->assertNotNull($s->secrets_rotated_at);
        $this->assertSame('SWIASYNTHETICAPIKEY02', $c->fresh()->credential('client_id'));
    }

    public function test_shop_on_another_domain_or_moved_away_is_not_or_no_longer_bound(): void
    {
        $c = $this->appConnection();
        $first = $this->register()->json('secret');
        $this->confirm($first)->assertNoContent();
        $this->assertSame('bound', $c->fresh()->credential('app_registration'));

        // The shop re-registers under a different URL: the old binding is released.
        $second = $this->register(url: 'https://andere-winkel.nl', shopSecret: $first)->json('secret');
        $this->confirm($second, $first, url: 'https://andere-winkel.nl')->assertNoContent();
        $c->refresh();
        $this->assertSame('pending', $c->credential('app_registration'));
        $this->assertNull($c->credential('client_id'));
        $this->assertNull(ShopwareAppShop::sole()->connection_id);
    }

    public function test_app_registration_mode_rejects_manual_client_credentials(): void
    {
        $this->expectException(ValidationException::class);
        app(ConnectionManager::class)->create($this->shop->memberships()->sole(), EcommercePlatform::Shopware6, ['app_registration' => true, 'store_url' => self::SHOP_URL, 'sales_channel_id' => self::CHANNEL, 'client_id' => 'SWIAMANUALCLIENTID01', 'client_secret' => 'SYNTHETIC_manual_secret_01']);
    }
}
