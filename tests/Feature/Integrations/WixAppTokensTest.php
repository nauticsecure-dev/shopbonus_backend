<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\WixConnector;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class WixAppTokensTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANCE = '11111111-1111-4111-8111-111111111111';

    private WebshopConnection $c;

    private int $mints = 0;

    /** @var array<string,mixed> */
    private array $tokenReply = ['access_token' => 'OauthNG.JWS.SYNTHETIC_minted_token_1', 'token_type' => 'Bearer', 'expires_in' => 14400];

    private int $tokenStatus = 200;

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.wix_app.enabled' => true, 'integrations.wix_app.app_id' => 'SYNTHETIC-app-id', 'integrations.wix_app.app_secret' => 'SYNTHETIC_app_secret_value']);
        $u = User::factory()->create();
        $shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        $this->public = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048]))['key'];
        [$this->c] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::Wix, ['instance_id' => self::INSTANCE, 'site_id' => '22222222-2222-4222-8222-222222222222', 'public_key' => $this->public]);
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver
        {
            public function addresses(string $h): array
            {
                return ['93.184.216.34'];
            }

            public function txtRecords(string $h): array
            {
                return [];
            }
        });
        Http::fake(function ($r) {
            if ($r->url() === 'https://www.wixapis.com/oauth2/token') {
                $this->mints++;

                return Http::response($this->tokenReply, $this->tokenStatus);
            }

            return Http::response(['instance' => ['instanceId' => self::INSTANCE], 'site' => ['siteId' => '22222222-2222-4222-8222-222222222222', 'url' => 'https://fietsen-utrecht.nl']]);
        });
    }

    private function test_(): array
    {
        return app(WixConnector::class)->testConnection($this->c->fresh());
    }

    public function test_app_mode_binds_app_without_storing_a_manual_token_and_prohibits_one(): void
    {
        $this->assertSame('SYNTHETIC-app-id', $this->c->credential('wix_app_id'));
        $this->assertNull($this->c->credential('access_token'));
        $rules = app(WixConnector::class)->connectRules();
        $this->assertTrue(Validator::make(['instance_id' => self::INSTANCE, 'site_id' => self::INSTANCE, 'public_key' => $this->public, 'access_token' => 'SYNTHETIC_manual_token'], $rules)->fails());
    }

    public function test_first_read_mints_token_with_client_credentials_and_reuses_it_until_near_expiry(): void
    {
        $this->assertTrue($this->test_()['ok']);
        $this->assertTrue($this->test_()['ok']);
        $this->assertSame(1, $this->mints);
        Http::assertSent(fn ($r) => $r->url() === 'https://www.wixapis.com/oauth2/token' && $r->method() === 'POST' && $r->data() === ['grant_type' => 'client_credentials', 'client_id' => 'SYNTHETIC-app-id', 'client_secret' => 'SYNTHETIC_app_secret_value', 'instance_id' => self::INSTANCE] && ! $r->hasHeader('Authorization'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/apps/v1/instance') && $r->header('Authorization') === ['OauthNG.JWS.SYNTHETIC_minted_token_1']);
        $c = $this->c->fresh();
        $this->assertEqualsWithDelta(now()->getTimestamp() + 14400, (int) $c->credential('access_token_expires_at'), 5);
        $this->assertStringNotContainsString('SYNTHETIC_minted_token_1', (string) $c->getRawOriginal('credentials'));
    }

    public function test_renewal_near_expiry_keeps_same_authority_for_in_flight_snapshots(): void
    {
        $this->test_();
        $before = $this->c->fresh();
        $this->travel(4)->hours();
        $this->tokenReply['access_token'] = 'OauthNG.JWS.SYNTHETIC_minted_token_2';
        $this->test_();
        $after = $this->c->fresh();
        $this->assertSame(2, $this->mints);
        $this->assertSame('OauthNG.JWS.SYNTHETIC_minted_token_2', $after->credential('access_token'));
        $this->assertSame($before->credential_generation, $after->credential_generation);
        $this->assertTrue($before->hasSameCredentialAuthority($after));
        // A manual rotation is still a different authority.
        app(ConnectionManager::class)->replaceCredentials($this->c->fresh(), ['instance_id' => self::INSTANCE, 'site_id' => '33333333-3333-4333-8333-333333333333', 'public_key' => $this->public]);
        $this->assertFalse($after->hasSameCredentialAuthority($this->c->fresh()));
    }

    public function test_changed_app_or_disabled_mode_fails_before_any_http(): void
    {
        config(['integrations.wix_app.app_id' => 'SYNTHETIC-other-app']);
        $this->assertCode('wix_app_changed');
        config(['integrations.wix_app.app_id' => 'SYNTHETIC-app-id', 'integrations.wix_app.enabled' => false]);
        $this->assertCode('wix_app_not_configured');
        Http::assertNothingSent();
    }

    public function test_invalid_or_rejected_token_response_stores_nothing(): void
    {
        $raw = $this->c->fresh()->getRawOriginal('credentials');
        $this->tokenReply = ['access_token' => 'short', 'expires_in' => 14400];
        $this->assertCode('provider_response_invalid');
        $this->tokenReply = ['access_token' => 'OauthNG.JWS.SYNTHETIC_minted_token_1', 'expires_in' => '14400'];
        $this->assertCode('provider_response_invalid');
        $this->tokenStatus = 401;
        $this->assertCode('provider_authentication_failed');
        $this->assertSame($raw, $this->c->fresh()->getRawOriginal('credentials'));
    }

    private function assertCode(string $code): void
    {
        try {
            $this->test_();
            $this->fail("Expected {$code}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
