<?php

namespace Tests\Feature\Banners;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Banners\BannerService;
use App\Services\Banners\LightspeedScriptPublisher;
use App\Services\Banners\NativeBannerPublisher;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\TestCase;

/**
 * Lightspeed C-Series automatic banner publication through one owned Shop Script
 * (documented POST/GET/DELETE /shop/scripts). Provider faked; no live store.
 */
class LightspeedScriptPublisherTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private WebshopConnection $connection;

    /** @var array<int, array<string,mixed>> remote scripts by ID */
    private array $scripts = [];

    private bool $createReturnsId = true;

    private int $writeStatus = 200;

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.example.com', 'integrations.lightspeed_banners.enabled' => true]);
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['verified_domain' => 'shop.example.com']);
        $banners = app(BannerService::class);
        $banners->publish($banners->create($this->shop, 'Synthetic Lightspeed publication', null, BannerFixture::config(), $this->owner->id), $this->owner->id);
        $banners->installation($this->shop)->forceFill(['allowed_origins' => ['https://shop.example.com']])->save();
        [$this->connection] = app(ConnectionManager::class)->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), EcommercePlatform::LightspeedC,
            ['shop_id' => '100001', 'cluster' => 'eu1', 'language' => 'en', 'api_key' => 'SYNTHETICkey123456', 'api_secret' => 'SYNTHETICsecret123456']);
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
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $this->assertStringStartsWith('https://api.webshopapp.com/en/', $r->url());
            $this->assertTrue($r->hasHeader('Authorization', 'Basic '.base64_encode('SYNTHETICkey123456:SYNTHETICsecret123456')));
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/en/shop.json') {
                return Http::response(['shop' => ['id' => 100001, 'mainDomain' => 'shop.example.com', 'status' => 'live', 'currency' => ['shortcode' => 'EUR']]]);
            }
            if ($path === '/en/orders.json') {
                return Http::response(['orders' => []]);
            }
            if ($r->method() !== 'GET') {
                $this->writes[] = $r->method().' '.$path;
                if ($this->writeStatus !== 200) {
                    return Http::response(['error' => 'denied'], $this->writeStatus);
                }
            }
            if ($path === '/en/shop/scripts.json' && $r->method() === 'POST') {
                $id = 24648 + count($this->scripts);
                $this->scripts[$id] = ['id' => $id, 'url' => null, 'body' => $r['shopScript']['body'], 'location' => $r['shopScript']['location']];

                // The documented create example has no ID; both shapes must be handled.
                return Http::response(['shopScript' => $this->createReturnsId ? $this->scripts[$id] : ['body' => $r['shopScript']['body'], 'location' => $r['shopScript']['location']]]);
            }
            if (preg_match('~\A/en/shop/scripts/([0-9]+)\.json\z~', (string) $path, $m)) {
                $id = (int) $m[1];
                if ($r->method() === 'DELETE') {
                    unset($this->scripts[$id]);

                    return Http::response('', 204);
                }

                return isset($this->scripts[$id]) ? Http::response(['shopScript' => $this->scripts[$id]]) : Http::response(['error' => 'not found'], 404);
            }
            throw new \RuntimeException("Unexpected provider path {$path}.");
        });
    }

    private function publisher(): LightspeedScriptPublisher
    {
        return app(LightspeedScriptPublisher::class);
    }

    public function test_install_creates_one_verified_script_that_loads_the_embed_with_the_key_and_removal_confirms_absence(): void
    {
        $result = $this->publisher()->install($this->connection, $this->owner);
        $this->assertSame(['native_configured', ['script' => 24648], 'not_observed'], [$result['state'], $result['remote_ids'], $result['storefront_delivery']]);
        $script = $this->scripts[24648];
        $key = app(BannerService::class)->installation($this->shop)->public_key;
        $this->assertSame('body', $script['location']);
        $this->assertStringContainsString('s.src="https://api.example.com/embed/v1.js"', $script['body']);
        $this->assertStringContainsString('"data-shopbonus-key","'.$key.'"', $script['body']);

        // Idempotent: a second install reuses the frozen plan without another create.
        $this->publisher()->install($this->connection, $this->owner);
        $this->assertSame(['POST /en/shop/scripts.json'], $this->writes);

        $this->assertSame('removed', $this->publisher()->remove($this->connection, $this->owner)['state']);
        $this->assertSame([], $this->scripts);
        $this->assertSame(['plan', 'script_intent', 'script_observed', 'removing', 'script_delete_intent', 'script_deleted', 'removed'],
            AuditEvent::where('action', 'like', 'integration.banner_ls_%')->orderBy('id')->pluck('action')->map(fn ($a) => substr($a, strlen('integration.banner_ls_')))->all());
    }

    public function test_create_without_an_id_stays_uncertain_until_a_matching_script_is_bound(): void
    {
        $this->createReturnsId = false;
        try {
            $this->publisher()->install($this->connection, $this->owner);
            $this->fail('An unconfirmed create must not be treated as installed.');
        } catch (ApiException $e) {
            $this->assertSame('native_banner_outcome_unknown', $e->errorCode);
        }
        // Never a blind second create.
        try {
            $this->publisher()->install($this->connection, $this->owner);
        } catch (ApiException $e) {
            $this->assertSame('native_banner_outcome_unknown', $e->errorCode);
        }
        $this->assertCount(1, $this->writes);

        // A foreign script is refused; the matching one is adopted.
        $this->scripts[1] = ['id' => 1, 'url' => 'https://evil.example/x.js', 'body' => null, 'location' => 'head'];
        try {
            $this->publisher()->bind($this->connection, 1, $this->owner);
            $this->fail('A script not matching the frozen plan must not be adopted.');
        } catch (ApiException $e) {
            $this->assertSame('native_banner_remote_mismatch', $e->errorCode);
        }
        $this->publisher()->bind($this->connection, 24648, $this->owner);
        $this->assertSame('native_configured', $this->publisher()->reconcile($this->connection, $this->owner)['state']);
    }

    public function test_a_tampered_or_missing_remote_script_is_reported_not_trusted(): void
    {
        $this->publisher()->install($this->connection, $this->owner);
        $this->scripts[24648]['body'] = 'alert(1)';
        try {
            $this->publisher()->reconcile($this->connection, $this->owner);
            $this->fail('A changed remote script must not pass reconciliation.');
        } catch (ApiException $e) {
            $this->assertSame('native_banner_remote_mismatch', $e->errorCode);
        }
        unset($this->scripts[24648]);
        $this->assertSame('remote_missing', $this->publisher()->reconcile($this->connection, $this->owner)['state']);
    }

    public function test_writes_need_the_flag_script_permission_and_integration_rights(): void
    {
        config(['integrations.lightspeed_banners.enabled' => false]);
        $this->assertSame('native_banner_disabled', $this->errorCode(fn () => $this->publisher()->install($this->connection, $this->owner)));
        config(['integrations.lightspeed_banners.enabled' => true]);

        $this->writeStatus = 403;
        $this->assertSame('native_banner_permission_missing', $this->errorCode(fn () => $this->publisher()->install($this->connection, $this->owner)));

        $outsider = User::factory()->create();
        $this->assertSame('native_banner_forbidden', $this->errorCode(fn () => $this->publisher()->install($this->connection, $outsider)));

        $readiness = app(NativeBannerPublisher::class)->readiness(EcommercePlatform::LightspeedC);
        $this->assertSame(['implemented_opt_in', true], [$readiness['state'], $readiness['automatic_publication']]);
    }

    private function errorCode(callable $call): ?string
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e->errorCode;
        }

        return null;
    }
}
