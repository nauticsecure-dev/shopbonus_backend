<?php

namespace Tests\Feature\Integrations;

use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\ShopifyOAuth;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.shopify_oauth.enabled' => true, 'integrations.shopify_oauth.client_id' => 'SYNTHETIC_client',
            'integrations.shopify_oauth.client_secret' => 'SYNTHETIC_client_secret']);
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }

            public function txtRecords(string $host): array
            {
                return [];
            }
        });
        Http::fake(fn ($r) => str_contains($r->url(), '/access_token') ? Http::response(['access_token' => 'shpat_SYNTHETIC_new_token',
            'refresh_token' => 'shprt_SYNTHETIC_refresh_token', 'expires_in' => 3600, 'refresh_token_expires_in' => 7776000, 'scope' => 'read_orders,read_products'])
            : Http::response(['data' => ['shop' => ['id' => 'gid://shopify/Shop/1', 'myshopifyDomain' => 'fietsen.myshopify.com', 'primaryDomain' => ['host' => 'fietsen-utrecht.nl']],
                'currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders'], ['handle' => 'read_products']]]]], 200, ['X-Shopify-API-Version' => '2026-10']));
    }

    private function request(array $query = []): Request
    {
        $r = Request::create('/api/v1/integrations/shopify/oauth/callback', 'GET', $query);
        $r->setUserResolver(fn () => $this->owner);
        $r->setLaravelSession($this->app['session']->driver());

        return $r;
    }

    /** @return array<string,string> */
    private function begin(): array
    {
        $data = app(ShopifyOAuth::class)->start($this->request(), $this->shop->memberships()->sole(), 'fietsen.myshopify.com');
        parse_str(parse_url($data['authorization_url'], PHP_URL_QUERY), $query);
        $params = ['shop' => 'fietsen.myshopify.com', 'state' => $query['state'], 'code' => 'SYNTHETIC_code', 'timestamp' => (string) now()->getTimestamp()];
        ksort($params);
        $params['hmac'] = hash_hmac('sha256', implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($params), $params)), 'SYNTHETIC_client_secret');

        return $params;
    }

    public function test_signed_one_time_callback_stores_expiring_pair_encrypted_and_does_not_connect(): void
    {
        $params = $this->begin();
        $c = app(ShopifyOAuth::class)->complete($this->request($params));
        $this->assertSame('setup_required', $c->status->value);
        $this->assertSame('shprt_SYNTHETIC_refresh_token', $c->credential('refresh_token'));
        $this->assertStringNotContainsString('shpat_', $c->getRawOriginal('credentials'));
        try {
            app(ShopifyOAuth::class)->complete($this->request($params));
            $this->fail('Replayed OAuth state accepted.');
        } catch (ApiException $e) {
            $this->assertSame('oauth_state_expired', $e->errorCode);
        }
        Http::assertSent(fn ($r) => str_contains($r->url(), '/access_token') && $r->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
        Http::assertSentCount(2);
    }

    public function test_invalid_hmac_is_rejected_before_code_exchange(): void
    {
        $params = $this->begin();
        $params['hmac'] = str_repeat('0', 64);
        try {
            app(ShopifyOAuth::class)->complete($this->request($params));
            $this->fail('Unsigned callback accepted.');
        } catch (ApiException $e) {
            $this->assertSame('oauth_callback_rejected', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_credential_rotation_invalidates_outstanding_state(): void
    {
        $params = $this->begin();
        $c = WebshopConnection::sole();
        app(ConnectionManager::class)->replaceCredentials($c, ['shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'SYNTHETIC_other_secret']);
        try {
            app(ShopifyOAuth::class)->complete($this->request($params));
            $this->fail('Old credential generation authorized.');
        } catch (ApiException $e) {
            $this->assertSame('oauth_state_expired', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_regular_refresh_preserves_grant_authority_and_generation(): void
    {
        $c = app(ShopifyOAuth::class)->complete($this->request($this->begin()));
        $meta = $c->credentials;
        $meta['access_token_expires_at'] = (string) now()->subSecond()->getTimestamp();
        $c->credentials = $meta;
        $c->save();
        $old = $c->fresh();
        $generation = $c->credential_generation;
        $this->assertSame('shpat_SYNTHETIC_new_token', app(ShopifyOAuth::class)->accessToken($c));
        $this->assertSame($generation, $c->credential_generation);
        $this->assertTrue($old->hasSameCredentialAuthority($c));
        $this->assertGreaterThan(now()->getTimestamp(), (int) $c->credential('access_token_expires_at'));
    }

    public function test_changed_app_authority_invalidates_even_an_unexpired_access_token(): void
    {
        $c = app(ShopifyOAuth::class)->complete($this->request($this->begin()));
        $generation = $c->credential_generation;
        config(['integrations.shopify_oauth.client_secret' => 'SYNTHETIC_rotated_app_secret']);
        try {
            app(ShopifyOAuth::class)->accessToken($c);
            $this->fail('Old app authority retained.');
        } catch (ApiException $e) {
            $this->assertSame('oauth_reauthorization_required', $e->errorCode);
        }
        $this->assertSame($generation + 1, $c->fresh()->credential_generation);
        $this->assertNull($c->fresh()->credential('access_token'));
        Http::assertSentCount(2);
    }
}
