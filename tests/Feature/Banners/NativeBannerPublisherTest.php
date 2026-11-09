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
use App\Services\Audit\AuditLogger;
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
            if ($r->method() === 'PUT') {
                $this->assertStringStartsWith('widget-templates/', $key);
                $this->assertFalse($r['create_new_version']);
                $data = $this->remote[$key];
                $data['template'] = $r['template'];
                $this->remote[$key] = $data;
                foreach ($this->remote as &$object) {
                    if (($object['widget_template_uuid'] ?? null) === $data['uuid']) {
                        $object['template'] = $data['template'];
                    }
                }
                unset($object);
                $this->writes[] = ['PUT', $key];
                if ($this->revoke) {
                    $this->shop->memberships()->delete();
                }
                if ($this->timeout === 'PUT') {
                    $this->timeout = null;
                    throw new ConnectionException('Synthetic lost upgrade response.');
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
        // The BigCommerce cart reader loads first (ordered defer) so cart changes drive live progress.
        $this->assertSame('<script defer src="https://api.example.com/embed/native-cart-v1.js" data-platform="bigcommerce"></script>'
            .'<script defer src="https://api.example.com/embed/v1.js" data-shopbonus-key="{{shopbonus_public_key}}"></script>', array_values($this->remote)[0]['template']);
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

    public function test_a_plan_frozen_before_the_cart_reader_keeps_its_original_template(): void
    {
        // Audit plans are append-only: an installation frozen without `cart_script_url` must still verify against
        // exactly the template it was created with, or reconcile/removal would report it as tampered.
        $template = new \ReflectionMethod(NativeBannerPublisher::class, 'template');
        $this->assertSame('<script async src="https://api.example.com/embed/v1.js" data-shopbonus-key="{{shopbonus_public_key}}"></script>',
            $template->invoke(null, ['script_url' => 'https://api.example.com/embed/v1.js']));
    }

    private function legacyPlan(): AuditEvent
    {
        $context = ['generation' => $this->connection->credential_generation, 'store' => $this->connection->external_shop_ref,
            'domain' => $this->shop->verified_domain, 'public_key' => app(BannerService::class)->installation($this->shop)->public_key,
            'script_url' => 'https://api.example.com/embed/v1.js', 'target' => $this->target + ['entity_id' => '']];
        // Match the original canonical field order (including target entity_id before sort_order).
        $context['target'] = ['template_file' => 'pages/home', 'region' => 'home_below_menu', 'entity_id' => '', 'sort_order' => 1];
        $context['digest'] = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
        $plan = app(AuditLogger::class)->record('integration.banner_plan', resource: $this->connection,
            webshopId: $this->shop->id, actorUserId: $this->owner->id, metadata: $context);
        $ids = ['template' => (string) Str::uuid(), 'widget' => (string) Str::uuid(), 'placement' => (string) Str::uuid()];
        $name = 'ShopBonus '.$plan->id;
        $template = '<script async src="https://api.example.com/embed/v1.js" data-shopbonus-key="{{shopbonus_public_key}}"></script>';
        $this->remote['widget-templates/'.$ids['template']] = ['uuid' => $ids['template'], 'name' => $name, 'channel_id' => 1, 'template' => $template];
        $this->remote['widgets/'.$ids['widget']] = ['uuid' => $ids['widget'], 'name' => $name, 'channel_id' => 1,
            'widget_template_uuid' => $ids['template'], 'template' => $template, 'widget_configuration' => ['shopbonus_public_key' => $context['public_key']]];
        $this->remote['placements/'.$ids['placement']] = ['uuid' => $ids['placement'], 'channel_id' => 1, 'widget_uuid' => $ids['widget'], 'status' => 'active'] + $context['target'];
        foreach ($ids as $step => $uuid) {
            app(AuditLogger::class)->record('integration.banner_'.$step.'_observed', resource: $this->connection,
                webshopId: $this->shop->id, actorUserId: $this->owner->id, metadata: ['plan_id' => $plan->id, 'uuid' => $uuid]);
        }

        return $plan;
    }

    public function test_matching_legacy_install_upgrades_in_place_without_rewriting_frozen_history(): void
    {
        $plan = $this->legacyPlan();
        $frozen = $plan->metadata;
        $ids = array_keys($this->remote);
        $this->assertSame('native_configured', $this->publisher()->install($this->connection, $this->target, $this->owner)['state']);
        $this->assertSame([['PUT', $ids[0]]], $this->writes);
        $this->assertSame($ids, array_keys($this->remote));
        $this->assertSame($frozen, $plan->refresh()->metadata);
        $this->assertStringContainsString('native-cart-v1.js', $this->remote[$ids[0]]['template']);
        $this->assertSame(1, AuditEvent::where('action', 'integration.banner_cart_upgrade_observed')->count());
        $this->publisher()->install($this->connection, $this->target, $this->owner);
        $this->publisher()->upgradeCart($this->connection, $this->owner);
        $this->assertCount(1, $this->writes);
        $this->assertSame('removed', $this->publisher()->remove($this->connection, $this->owner)['state']);
        $this->assertSame([], $this->remote);
    }

    public function test_lost_upgrade_response_is_reconciled_without_replaying_put(): void
    {
        $this->legacyPlan();
        $this->timeout = 'PUT';
        $this->error('native_banner_unconfirmed', fn () => $this->publisher()->upgradeCart($this->connection, $this->owner));
        $this->assertSame(1, AuditEvent::where('action', 'integration.banner_cart_upgrade_intent')->count());
        $this->assertSame('native_configured', $this->publisher()->upgradeCart($this->connection, $this->owner)['state']);
        $this->assertCount(1, $this->writes);
        $this->assertSame(1, AuditEvent::where('action', 'integration.banner_cart_upgrade_observed')->count());
    }

    public function test_legacy_upgrade_rejects_foreign_configuration_or_disabled_writes(): void
    {
        $this->legacyPlan();
        config(['integrations.bigcommerce_banners.enabled' => false]);
        $this->error('native_banner_disabled', fn () => $this->publisher()->upgradeCart($this->connection, $this->owner));
        config(['integrations.bigcommerce_banners.enabled' => true]);
        $key = array_key_first($this->remote);
        $this->remote[$key]['template'] = '<script>foreign()</script>';
        $this->error('native_banner_remote_mismatch', fn () => $this->publisher()->upgradeCart($this->connection, $this->owner));
        $this->assertSame([], $this->writes);
    }

    public function test_revoked_authority_cannot_confirm_an_upgrade_and_foreign_actor_cannot_start_one(): void
    {
        $this->legacyPlan();
        $this->error('native_banner_forbidden', fn () => $this->publisher()->upgradeCart($this->connection, User::factory()->create()));
        $this->revoke = true;
        $this->error('native_banner_forbidden', fn () => $this->publisher()->upgradeCart($this->connection, $this->owner));
        $this->assertCount(1, $this->writes);
        $this->assertSame(0, AuditEvent::where('action', 'integration.banner_cart_upgrade_observed')->count());
    }

    public function test_other_platforms_require_real_native_app_or_manual_installation(): void
    {
        // Lightspeed C now publishes through its documented Shop Scripts API (LightspeedScriptPublisherTest).
        // Ecwid/Lightspeed E publish via app storage (EcwidAppPublisherTest); Squarespace is verified-manual.
        foreach ([EcommercePlatform::LightspeedE, EcommercePlatform::Ecwid] as $p) {
            $this->assertTrue($this->publisher()->readiness($p)['automatic_publication']);
        }
        $this->assertFalse($this->publisher()->readiness(EcommercePlatform::Squarespace)['automatic_publication']);
        $this->assertTrue($this->publisher()->readiness(EcommercePlatform::Squarespace)['automatic_verification']);
        $this->artisan('shopbonus:banner-native', ['action' => 'readiness', 'id' => 'bigcommerce'])->assertSuccessful();
        $this->artisan('shopbonus:banner-native', ['action' => 'remove', 'id' => $this->connection->public_id])->assertFailed();
    }
}
