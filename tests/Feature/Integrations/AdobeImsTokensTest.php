<?php

namespace Tests\Feature\Integrations;

use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\AdobeImsTokens;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\MagentoConnector;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AdobeImsTokensTest extends TestCase
{
    use RefreshDatabase;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    private function input(): array
    {
        return ['deployment' => 'saas', 'saas_api_url' => 'https://na1.api.commerce.adobe.com/SYNTHETIC_TENANT', 'store_url' => 'https://shop.example.com', 'store_id' => '2', 'store_code' => 'default', 'ims_client_id' => 'SYNTHETIC_CLIENT', 'ims_client_secret' => 'SYNTHETIC_CLIENT_SECRET'];
    }

    private function connection(): WebshopConnection
    {
        $u = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'shop.example.com', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);

        return app(ConnectionManager::class)->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), EcommercePlatform::Magento, $this->input())[0];
    }

    private function mint(): array
    {
        return ['access_token' => 'SYNTHETIC_IMS_ACCESS_TOKEN', 'token_type' => 'bearer', 'expires_in' => 3600];
    }

    public function test_native_read_mints_once_encrypts_and_reuses_scoped_token_then_renews(): void
    {
        $c = $this->connection();
        $before = $c->replicate();
        $before->id = $c->id;
        $before->setRawAttributes($c->getAttributes(), true);
        Http::fake(fn ($r) => str_contains($r->url(), 'adobelogin.com') ? Http::response($this->mint()) : Http::response([['id' => 2, 'code' => 'default', 'secure_base_url' => 'https://shop.example.com/']]));
        $this->assertTrue((new MagentoConnector)->testConnection($c)['ok']);
        $this->assertTrue((new MagentoConnector)->testConnection($c)['ok']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->url() === 'https://ims-na1.adobelogin.com/ims/token/v3' && $r['grant_type'] === 'client_credentials' && $r['scope'] === AdobeImsTokens::SCOPES && $r['client_secret'] === 'SYNTHETIC_CLIENT_SECRET' && str_starts_with($r->header('Content-Type')[0], 'application/x-www-form-urlencoded'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/SYNTHETIC_TENANT/V1/store/storeConfigs') && $r->hasHeader('Store', 'default') && $r->hasHeader('Authorization', 'Bearer SYNTHETIC_IMS_ACCESS_TOKEN'));
        $this->assertStringNotContainsString('SYNTHETIC_IMS_ACCESS_TOKEN', $c->fresh()->getRawOriginal('credentials'));
        $this->assertStringNotContainsString('SYNTHETIC_CLIENT_SECRET', $c->fresh()->getRawOriginal('credentials'));
        $this->assertTrue($before->hasSameCredentialAuthority($c->fresh()));
        $this->travel(56)->minutes();
        app(AdobeImsTokens::class)->accessToken($c);
        Http::assertSentCount(4);
        $this->assertSame(1, $c->fresh()->credential_generation);
        $this->assertDatabaseHas('audit_events', ['action' => 'integration.oauth_refreshed']);
    }

    public function test_invalid_credentials_combinations_and_legacy_bridge_are_validated(): void
    {
        $rules = (new MagentoConnector)->connectRules();
        $this->assertTrue(Validator::make($this->input(), $rules)->passes());
        $this->assertTrue(Validator::make([], $rules)->passes());
        foreach ([array_replace($this->input(), ['deployment' => 'self_hosted']), array_replace($this->input(), ['access_token' => 'SYNTHETIC_STATIC_TOKEN']), array_diff_key($this->input(), ['ims_client_secret' => true]), array_diff_key($this->input(), ['ims_client_id' => true, 'ims_client_secret' => true])] as $input) {
            $this->assertTrue(Validator::make($input, $rules)->fails());
        }
    }

    public function test_provider_failure_or_malformed_token_does_not_save_credentials(): void
    {
        $c = $this->connection();
        $old = $c->getRawOriginal('credentials');
        foreach ([Http::response(['error' => 'invalid_client'], 401), Http::response(['access_token' => 'bad', 'expires_in' => 3600, 'token_type' => 'bearer']), Http::response(array_replace($this->mint(), ['expires_in' => '3600'])), Http::response(array_replace($this->mint(), ['token_type' => 'other'])), Http::response(array_replace($this->mint(), ['token_type' => ['bearer']])), Http::response(array_replace($this->mint(), ['expires_in' => 60]))] as $response) {
            Http::fake(fn () => $response);
            try {
                app(AdobeImsTokens::class)->accessToken($c);
                $this->fail('Invalid response accepted.');
            } catch (ApiException) {
                $this->assertSame($old, $c->fresh()->getRawOriginal('credentials'));
            }
        }
    }

    public function test_stale_models_paused_disconnected_foreign_identity_and_lost_ownership_cannot_mint(): void
    {
        Http::fake();
        foreach (['paused', 'disconnected', 'tenant', 'ownership'] as $case) {
            $c = $this->connection();
            if ($case === 'paused') {
                $c->fresh()->update(['paused_at' => now()]);
            }
            if ($case === 'disconnected') {
                $c->fresh()->update(['status' => ConnectionStatus::Disconnected]);
            }
            if ($case === 'tenant') {
                $c->fresh()->update(['external_shop_ref' => 'foreign']);
            }
            if ($case === 'ownership') {
                $this->shop->forceFill(['ownership_verified_at' => null])->save();
            }
            try {
                app(AdobeImsTokens::class)->accessToken($c);
                $this->fail('Inactive identity accepted.');
            } catch (ApiException) {
                $this->assertNull($c->fresh()->credential('access_token'));
            }
            $this->shop->forceFill(['ownership_verified_at' => now()])->save();
        }
        Http::assertNothingSent();
    }

    public function test_rotation_or_pause_during_response_does_not_commit_a_token(): void
    {
        foreach (['rotate', 'pause'] as $case) {
            $c = $this->connection();
            Http::fake(function () use ($c, $case) {
                $current = $c->fresh();
                $current->update($case === 'rotate' ? ['credential_generation' => 2] : ['paused_at' => now()]);

                return Http::response($this->mint());
            });
            try {
                app(AdobeImsTokens::class)->accessToken($c);
                $this->fail('Changed connection accepted.');
            } catch (ApiException) {
                $this->assertNull($c->fresh()->credential('access_token'));
            }
        }
    }

    public function test_renewal_lock_and_changed_grant_do_not_allow_stale_tokens(): void
    {
        $c = $this->connection();
        $lock = Cache::lock('adobe-ims:'.$c->public_id, 55);
        $this->assertTrue($lock->get());
        Http::fake();
        try {
            app(AdobeImsTokens::class)->accessToken($c);
            $this->fail('Concurrent renewal accepted.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->status);
        } finally {
            $lock->release();
        }
        $current = $c->fresh();
        $credentials = $current->credentials;
        $credentials['ims_client_secret'] = 'CHANGED_SYNTHETIC_SECRET';
        $current->update(['credentials' => $credentials]);
        $this->assertFalse($c->hasSameCredentialAuthority($current->fresh()));
        try {
            app(AdobeImsTokens::class)->accessToken($c);
            $this->fail('Changed grant accepted.');
        } catch (ApiException) {
            Http::assertNothingSent();
        }
    }

    public function test_cached_token_cannot_be_reused_from_a_stale_paused_snapshot(): void
    {
        $c = $this->connection();
        Http::fake(fn () => Http::response($this->mint()));
        app(AdobeImsTokens::class)->accessToken($c);
        $c->fresh()->update(['paused_at' => now()]);
        try {
            app(AdobeImsTokens::class)->accessToken($c);
            $this->fail('A cached token bypassed the current pause.');
        } catch (ApiException $e) {
            $this->assertSame('connection_paused', $e->errorCode);
            Http::assertSentCount(1);
        }
    }
}
