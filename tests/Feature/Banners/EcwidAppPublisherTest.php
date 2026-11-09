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
use App\Services\Banners\EcwidAppPublisher;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\TestCase;

/** Ecwid / Lightspeed E-Series publication via the documented app storage `public` key. Provider faked. */
class EcwidAppPublisherTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private WebshopConnection $connection;

    private ?string $stored = null;

    private bool $loseWrite = false;

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.example.com', 'integrations.ecwid_banners.enabled' => true, 'integrations.ecwid_banners.client_id' => 'shopbonus-app']);
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['verified_domain' => 'shop.example.com']);
        $banners = app(BannerService::class);
        $banners->publish($banners->create($this->shop, 'Synthetic Ecwid publication', null, BannerFixture::config(), $this->owner->id), $this->owner->id);
        $banners->installation($this->shop)->forceFill(['allowed_origins' => ['https://shop.example.com']])->save();
        [$this->connection] = app(ConnectionManager::class)->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), EcommercePlatform::Ecwid,
            ['store_id' => '1003', 'access_token' => 'secret_SYNTHETICtoken123', 'client_secret' => 'SYNTHETICclientsecret']);
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
            $this->assertTrue($r->hasHeader('Authorization', 'Bearer secret_SYNTHETICtoken123'));
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/api/v3/1003/profile') {
                return Http::response(['generalInfo' => ['storeId' => 1003, 'storeUrl' => 'https://shop.example.com'], 'account' => ['suspended' => false, 'availableFeatures' => ['API']]]);
            }
            if ($path === '/api/v3/1003/orders') {
                return Http::response(['items' => []]);
            }
            $this->assertSame('/api/v3/1003/storage/public', $path);
            if ($r->method() === 'GET') {
                return $this->stored === null ? Http::response(['errorMessage' => 'not found'], 404) : Http::response([['key' => 'public', 'value' => $this->stored]]);
            }
            $this->writes[] = $r->method();
            if ($r->method() === 'POST') {
                $this->stored = $r->data()['value'];
                if ($this->loseWrite) {
                    return Http::response('', 504);
                }
            } else {
                $this->stored = null;
            }

            return Http::response(['success' => true]);
        });
    }

    private function publisher(): EcwidAppPublisher
    {
        return app(EcwidAppPublisher::class);
    }

    public function test_install_writes_one_frozen_config_reads_back_and_is_idempotent(): void
    {
        $result = $this->publisher()->install($this->connection, $this->owner);
        $this->assertSame('native_configured', $result['state']);
        $key = app(BannerService::class)->installation($this->shop)->public_key;
        $this->assertSame(['schema' => 'shopbonus.ecwid-app.v1', 'embed' => 'https://api.example.com/embed/v1.js', 'public_key' => $key], json_decode($this->stored, true));
        $this->publisher()->install($this->connection, $this->owner);
        $this->assertSame(['POST'], $this->writes);
        $this->assertSame(1, AuditEvent::where('action', 'integration.banner_ecwid_config_observed')->count());
    }

    public function test_lost_write_response_is_resolved_by_read_back_without_a_second_write(): void
    {
        $this->loseWrite = true;
        try {
            $this->publisher()->install($this->connection, $this->owner);
            $this->fail('Lost response must not report success.');
        } catch (ApiException $e) {
            $this->assertSame('native_banner_unconfirmed', $e->errorCode);
        }
        $this->loseWrite = false;
        $this->assertSame('incomplete_or_uncertain', $this->publisher()->reconcile($this->connection, $this->owner)['state']);
        $this->assertSame('native_configured', $this->publisher()->install($this->connection, $this->owner)['state']);
        $this->assertSame(['POST'], $this->writes);
    }

    public function test_foreign_value_is_never_overwritten_or_deleted(): void
    {
        $this->stored = '{"color":"red"}';
        try {
            $this->publisher()->install($this->connection, $this->owner);
            $this->fail('Foreign config must block install.');
        } catch (ApiException $e) {
            $this->assertSame('native_banner_remote_mismatch', $e->errorCode);
        }
        try {
            $this->publisher()->remove($this->connection, $this->owner);
            $this->fail('Foreign config must block removal.');
        } catch (ApiException $e) {
            $this->assertSame('native_banner_remote_mismatch', $e->errorCode);
        }
        $this->assertSame([], $this->writes);
        $this->assertSame('{"color":"red"}', $this->stored);
    }

    public function test_remove_deletes_owned_config_and_confirms_absence(): void
    {
        $this->publisher()->install($this->connection, $this->owner);
        $this->assertSame('removed', $this->publisher()->remove($this->connection, $this->owner)['state']);
        $this->assertNull($this->stored);
        $this->assertSame('removed', $this->publisher()->remove($this->connection, $this->owner)['state']);
        $this->assertSame(['POST', 'DELETE'], $this->writes);
        $this->assertSame(1, AuditEvent::where('action', 'integration.banner_ecwid_removed')->count());
    }

    public function test_disabled_writes_unconfigured_app_and_unauthorized_actor_fail_closed(): void
    {
        config(['integrations.ecwid_banners.client_id' => null]);
        $this->assertCode('native_banner_app_unconfigured', fn () => $this->publisher()->install($this->connection, $this->owner));
        config(['integrations.ecwid_banners.client_id' => 'shopbonus-app', 'integrations.ecwid_banners.enabled' => false]);
        $this->assertCode('native_banner_disabled', fn () => $this->publisher()->install($this->connection, $this->owner));
        config(['integrations.ecwid_banners.enabled' => true]);
        $this->assertCode('native_banner_forbidden', fn () => $this->publisher()->install($this->connection, User::factory()->create()));
        $this->assertSame([], $this->writes);
    }

    public function test_command_dispatches_and_readiness_is_automatic(): void
    {
        $this->artisan('shopbonus:banner-native', ['action' => 'install', 'id' => $this->connection->public_id, '--actor' => $this->owner->public_id])->assertSuccessful();
        $this->artisan('shopbonus:banner-native', ['action' => 'bind', 'id' => $this->connection->public_id, '--actor' => $this->owner->public_id])->assertFailed();
        $this->artisan('shopbonus:banner-native', ['action' => 'remove', 'id' => $this->connection->public_id, '--actor' => $this->owner->public_id])->assertSuccessful();
        $this->assertNull($this->stored);
    }

    private function assertCode(string $code, \Closure $fn): void
    {
        try {
            $fn();
            $this->fail('Expected '.$code);
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
