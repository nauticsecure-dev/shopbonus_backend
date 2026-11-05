<?php

namespace Tests\Feature\Banners;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Models\AuditEvent;
use App\Models\BannerCampaign;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Banners\BannerService;
use App\Services\Banners\NativeBannerPublisher;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\TestCase;

class NativeBannerPublisherTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private WebshopConnection $connection;

    private array $remote = [];

    private array $writes = [];

    private ?string $timeout = null;

    private bool $missingRegion = false;

    private bool $wrongStore = false;

    private bool $revoke = false;

    private array $target = ['template_file' => 'pages/home', 'region' => 'home_below_menu', 'sort_order' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.example.com', 'integrations.bigcommerce_banners.enabled' => true]);
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['verified_domain' => 'shop.example.com']);
        $banners = app(BannerService::class);
        $campaign = $banners->create($this->shop, 'Synthetic native publication', null, BannerFixture::config(), $this->owner->id);
        $banners->publish($campaign, $this->owner->id);
        $this->connection = app(ConnectionManager::class)->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), EcommercePlatform::BigCommerce, ['store_hash' => 'abc123', 'access_token' => 'SYNTHETIC_access_token', 'client_secret' => 'SYNTHETIC_client_secret'])[0];
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
        Http::fake(function (Request $r) {
            $this->assertSame('api.bigcommerce.com', parse_url($r->url(), PHP_URL_HOST));
            $this->assertSame(['SYNTHETIC_access_token'], $r->header('X-Auth-Token'));
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/stores/abc123/v2/store') {
                return Http::response(['id' => $this->wrongStore ? 'foreign' : 'abc123', 'secure_url' => 'https://shop.example.com', 'features' => ['multi_storefront_enabled' => false]]);
            }
            if ($path === '/stores/abc123/v3/content/regions') {
                return Http::response(['data' => $this->missingRegion ? [] : [['name' => 'home_below_menu']]]);
            }
            $key = str_replace('/stores/abc123/v3/content/', '', $path);
            if ($r->method() === 'POST') {
                $uuid = (string) Str::uuid();
                $data = $r->data() + ['uuid' => $uuid];
                $this->remote[$key.'/'.$uuid] = $data;
                $this->writes[] = ['POST', $key];
                if ($this->revoke) {
                    $this->shop->memberships()->delete();
                }
                if ($this->timeout === 'POST') {
                    $this->timeout = null;
                    throw new ConnectionException('Synthetic lost response.');
                }

                return Http::response(['data' => $data]);
            }
            if ($r->method() === 'DELETE') {
                unset($this->remote[$key]);
                $this->writes[] = ['DELETE', $key];
                if ($this->timeout === 'DELETE') {
                    $this->timeout = null;
                    throw new ConnectionException('Synthetic lost delete response.');
                }

                return Http::response(null, 204);
            }

            return isset($this->remote[$key]) ? Http::response(['data' => $this->remote[$key]]) : Http::response([], 404);
        });
    }

    private function publisher(): NativeBannerPublisher
    {
        return app(NativeBannerPublisher::class);
    }

    private function error(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_installs_once_reconciles_and_removes_in_dependency_order(): void
    {
        $first = $this->publisher()->install($this->connection, $this->target, $this->owner);
        $this->assertSame('native_configured', $first['state']);
        $this->assertSame('not_observed', $first['storefront_delivery']);
        $again = $this->publisher()->install($this->connection, array_reverse($this->target, true), $this->owner);
        $this->assertSame($first, $again);
        $this->assertCount(3, $this->writes);
        $this->assertStringContainsString('https://api.example.com/embed/v1.js', array_values($this->remote)[0]['template']);
        $this->assertSame('removed', $this->publisher()->remove($this->connection, $this->owner)['state']);
        $this->publisher()->remove($this->connection, $this->owner);
        $this->assertCount(6, $this->writes);
        $this->assertStringStartsWith('placements/', $this->writes[3][1]);
        $this->assertStringStartsWith('widgets/', $this->writes[4][1]);
        $this->assertStringStartsWith('widget-templates/', $this->writes[5][1]);
        $this->assertSame([], $this->remote);
    }

    public function test_uncertain_create_requires_verified_bind_before_resume(): void
    {
        $this->timeout = 'POST';
        $this->error('native_banner_unconfirmed', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        $this->error('native_banner_outcome_unknown', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        $this->error('native_banner_outcome_unknown', fn () => $this->publisher()->remove($this->connection, $this->owner));
        $this->assertCount(1, $this->writes);
        $native = array_values($this->remote)[0];
        $this->publisher()->bind($this->connection, 'template', $native['uuid'], $this->owner);
        $this->assertSame('native_configured', $this->publisher()->install($this->connection, $this->target, $this->owner)['state']);
        $this->assertCount(3, $this->writes);
    }

    public function test_uncertain_delete_reconciles_absence_without_duplicate_delete(): void
    {
        $this->publisher()->install($this->connection, $this->target, $this->owner);
        $this->timeout = 'DELETE';
        $this->error('native_banner_unconfirmed', fn () => $this->publisher()->remove($this->connection, $this->owner));
        $this->error('native_banner_removing', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        $this->assertSame('removed', $this->publisher()->remove($this->connection, $this->owner)['state']);
        $this->assertCount(6, $this->writes);
    }

    public function test_foreign_native_configuration_cannot_be_deleted_or_adopted(): void
    {
        $this->publisher()->install($this->connection, $this->target, $this->owner);
        $key = array_key_last($this->remote);
        $this->remote[$key]['region'] = 'foreign';
        $this->error('native_banner_remote_mismatch', fn () => $this->publisher()->remove($this->connection, $this->owner));
        $this->assertCount(3, $this->writes);
    }

    public function test_live_authority_is_revalidated_after_native_create(): void
    {
        $this->revoke = true;
        $this->error('native_banner_forbidden', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        $this->assertCount(1, $this->writes);
        $this->assertSame(0, AuditEvent::where('action', 'integration.banner_template_observed')->count());
    }

    public function test_disabled_foreign_store_and_missing_region_never_write(): void
    {
        config(['integrations.bigcommerce_banners.enabled' => false]);
        $this->error('native_banner_disabled', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        config(['integrations.bigcommerce_banners.enabled' => true]);
        $this->wrongStore = true;
        $this->error('provider_store_mismatch', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        $this->wrongStore = false;
        $this->missingRegion = true;
        $this->error('native_banner_region_missing', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        $this->assertSame([], $this->writes);
    }

    public function test_current_permissions_frozen_target_and_generation_are_enforced(): void
    {
        $other = User::factory()->create();
        $this->error('native_banner_forbidden', fn () => $this->publisher()->install($this->connection, $this->target, $other));
        $this->publisher()->install($this->connection, $this->target, $this->owner);
        $this->error('native_banner_plan_changed', fn () => $this->publisher()->install($this->connection, ['sort_order' => 2] + $this->target, $this->owner));
        $this->connection->increment('credential_generation');
        $this->error('native_banner_plan_changed', fn () => $this->publisher()->install($this->connection->fresh(), $this->target, $this->owner));
        $this->assertCount(3, $this->writes);
    }

    public function test_paused_connection_invalid_target_and_absent_live_campaign_cannot_write(): void
    {
        $this->error('native_banner_target_invalid', fn () => $this->publisher()->install($this->connection, ['region' => '../foreign'] + $this->target, $this->owner));
        $this->connection->forceFill(['paused_at' => now()])->save();
        try {
            $this->publisher()->install($this->connection, $this->target, $this->owner);
            $this->fail('Paused publication must fail.');
        } catch (ApiException $e) {
            $this->assertContains($e->status, [409, 503]);
        }
        $this->connection->forceFill(['paused_at' => null])->save();
        BannerCampaign::where('webshop_id', $this->shop->id)->update(['status' => 'paused']);
        $this->error('native_banner_no_live_campaign', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        $this->assertSame([], $this->writes);
    }

    public function test_uncertain_binding_rejects_foreign_payload_before_resuming(): void
    {
        $this->timeout = 'POST';
        $this->error('native_banner_unconfirmed', fn () => $this->publisher()->install($this->connection, $this->target, $this->owner));
        $key = array_key_first($this->remote);
        $this->remote[$key]['template'] = '<script>foreign()</script>';
        $this->error('native_banner_remote_mismatch', fn () => $this->publisher()->bind($this->connection, 'template', $this->remote[$key]['uuid'], $this->owner));
        $this->assertSame(0, AuditEvent::where('action', 'integration.banner_template_observed')->count());
        $this->assertCount(1, $this->writes);
    }

    public function test_remote_uuid_mismatch_and_current_domain_change_block_progress(): void
    {
        $this->publisher()->install($this->connection, $this->target, $this->owner);
        $key = array_key_first($this->remote);
        $this->remote[$key]['uuid'] = (string) Str::uuid();
        $this->error('native_banner_unconfirmed', fn () => $this->publisher()->reconcile($this->connection, $this->owner));
        $this->shop->forceFill(['verified_domain' => 'changed.example.com'])->save();
        $this->error('store_identity_unverified', fn () => $this->publisher()->remove($this->connection, $this->owner));
        $this->assertCount(3, $this->writes);
    }

    public function test_operator_command_installs_and_reconciles_using_reviewed_json(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'b73-target-');
        try {
            file_put_contents($file, json_encode($this->target, JSON_THROW_ON_ERROR));
            $this->artisan('shopbonus:banner-native', ['action' => 'install', 'id' => $this->connection->public_id, '--actor' => $this->owner->public_id, '--file' => $file])->assertSuccessful();
            $this->artisan('shopbonus:banner-native', ['action' => 'reconcile', 'id' => $this->connection->public_id, '--actor' => $this->owner->public_id])->assertSuccessful();
            $this->assertCount(3, $this->writes);
            file_put_contents($file, '{"region":"a","region":"b"}');
            $this->artisan('shopbonus:banner-native', ['action' => 'install', 'id' => $this->connection->public_id, '--actor' => $this->owner->public_id, '--file' => $file])->assertFailed();
            $this->assertCount(3, $this->writes);
        } finally {
            unlink($file);
        }
    }

    public function test_other_platforms_require_real_native_app_or_manual_installation(): void
    {
        foreach ([EcommercePlatform::LightspeedC, EcommercePlatform::LightspeedE, EcommercePlatform::Squarespace] as $p) {
            $this->assertFalse($this->publisher()->readiness($p)['automatic_publication']);
        }
        $this->artisan('shopbonus:banner-native', ['action' => 'readiness', 'id' => 'bigcommerce'])->assertSuccessful();
        $this->artisan('shopbonus:banner-native', ['action' => 'remove', 'id' => $this->connection->public_id])->assertFailed();
    }
}
