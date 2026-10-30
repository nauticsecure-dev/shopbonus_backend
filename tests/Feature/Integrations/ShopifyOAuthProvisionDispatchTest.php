<?php

namespace Tests\Feature\Integrations;

use App\Integrations\ShopifyOAuth;
use App\Jobs\ProvisionShopifyWebhook;
use App\Models\AuditEvent;
use App\Models\ShopifyAuthorization;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyOAuthProvisionDispatchTest extends TestCase
{
    private User $owner;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        // This fixture needs real commits to exercise after-commit dispatch. Rebuild only the
        // configured test database; do not run unrelated historical down migrations on teardown.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });

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

    public function test_committed_authorization_dispatches_seven_bounded_topics(): void
    {
        config(['integrations.shopify_webhooks.enabled' => true, 'queue.default' => 'database']);
        Bus::fake([ProvisionShopifyWebhook::class]);
        $c = app(ShopifyOAuth::class)->complete($this->request($this->begin()));
        Bus::assertDispatchedTimes(ProvisionShopifyWebhook::class, 7);
        Bus::assertDispatched(ProvisionShopifyWebhook::class, fn ($job) => $job->connectionId === $c->id && $job->generation === $c->credential_generation && $job->topic === 'APP_UNINSTALLED');
        $this->assertSame('succeeded', ShopifyAuthorization::sole()->status);
        Http::assertSentCount(2);
    }

    public function test_synchronous_setup_failure_does_not_relabel_or_rollback_committed_authorization(): void
    {
        config(['integrations.shopify_webhooks.enabled' => true, 'queue.default' => 'sync', 'app.url' => 'http://localhost']);
        $c = app(ShopifyOAuth::class)->complete($this->request($this->begin()));
        $this->assertNotNull($c->credential('access_token'));
        $this->assertSame('succeeded', ShopifyAuthorization::sole()->status);
        $this->assertSame('setup_required', $c->status->value);
        $this->assertSame(7, AuditEvent::where('action', 'integration.webhook_dispatch_failed')->count());
        $this->assertSame('webhook_async_queue_required', AuditEvent::where('action', 'integration.webhook_dispatch_failed')->first()->metadata['failure']);
        Http::assertSentCount(2);
    }
}
