<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectionManager;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WixAppRemovedTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANCE = '11111111-1111-4111-8111-111111111111';

    private WebshopConnection $c;

    private \OpenSSLAsymmetricKey $key;

    private int $instanceStatus = 401;

    protected function setUp(): void
    {
        parent::setUp();
        $u = User::factory()->create();
        $shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        [$this->c] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::Wix, ['instance_id' => self::INSTANCE, 'site_id' => '22222222-2222-4222-8222-222222222222',
            'access_token' => 'SYNTHETIC_wix_app_token', 'public_key' => openssl_pkey_get_details($this->key)['key']]);
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
        Http::fake(fn () => Http::response($this->instanceStatus === 200
            ? ['instance' => ['instanceId' => self::INSTANCE], 'site' => ['siteId' => '22222222-2222-4222-8222-222222222222', 'url' => 'https://fietsen-utrecht.nl']]
            : ['message' => 'unauthorized'], $this->instanceStatus));
    }

    private function send(string $eventType, array $payload): TestResponse
    {
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $message = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode(['data' => json_encode(['instanceId' => self::INSTANCE, 'eventType' => $eventType, 'data' => json_encode($payload)]), 'iat' => now()->getTimestamp()]));
        openssl_sign($message, $sig, $this->key, OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/api/v1/webhooks/wix/'.$this->c->webhook_token, [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'], $message.'.'.$b64($sig));
    }

    public function test_app_removed_disconnects_only_after_native_authentication_failure(): void
    {
        $this->send('AppRemoved', ['appId' => 'SYNTHETIC-app-id'])->assertStatus(202);
        $c = $this->c->fresh();
        $this->assertSame('disconnected', $c->status->value);
        $e = WebhookEvent::sole();
        $this->assertSame('processed', $e->status->value);
        $this->assertTrue($e->result['credentials_revoked']);
    }

    public function test_replayed_removal_with_valid_current_grant_is_ignored_and_redelivery_dedupes(): void
    {
        $this->instanceStatus = 200;
        $this->send('AppRemoved', ['appId' => 'SYNTHETIC-app-id'])->assertStatus(202);
        $this->assertSame('setup_required', $this->c->fresh()->status->value);
        $this->assertSame('uninstall_current_grant_still_valid', WebhookEvent::sole()->error_code);
        $this->send('AppRemoved', ['appId' => 'SYNTHETIC-app-id'])->assertOk()->assertJsonPath('data.duplicate', true);
    }

    public function test_app_installed_is_stored_without_effects(): void
    {
        $this->send('AppInstalled', ['appId' => 'SYNTHETIC-app-id', 'originInstanceId' => ''])->assertStatus(202);
        $this->assertSame('other', WebhookEvent::sole()->result['kind']);
        $this->assertSame('setup_required', $this->c->fresh()->status->value);
        Http::assertNothingSent();
    }
}
