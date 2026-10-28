<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\ConnectorRegistry;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NativeStoreBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_native_store_identity_stops_every_adapter_before_any_order_read(): void
    {
        $u = User::factory()->create();
        $shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
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
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $public = openssl_pkey_get_details($key)['key'];
        $cases = [
            [EcommercePlatform::BigCommerce, ['store_hash' => 'abc123', 'access_token' => 'SYNTHETIC_bc_token', 'client_secret' => 'SYNTHETIC_bc_secret'], '4711', 1],
            [EcommercePlatform::Ecwid, ['store_id' => '1003', 'access_token' => 'SYNTHETIC_ecwid_token', 'client_secret' => 'SYNTHETIC_ecwid_secret'], '4711', 1],
            [EcommercePlatform::Wix, ['instance_id' => '11111111-1111-4111-8111-111111111111', 'site_id' => '22222222-2222-4222-8222-222222222222', 'access_token' => 'SYNTHETIC_wix_token', 'public_key' => $public], '33333333-3333-4333-8333-333333333333', 1],
            [EcommercePlatform::PrestaShop, ['store_url' => 'https://fietsen-utrecht.nl', 'webservice_key' => 'SYNTHETIC_presta_key', 'shop_id' => '1', 'timezone' => 'Europe/Amsterdam'], '4711', 1],
            [EcommercePlatform::Shopware6, ['store_url' => 'https://fietsen-utrecht.nl', 'client_id' => 'SYNTHETIC_shopware_client', 'client_secret' => 'SYNTHETIC_shopware_secret', 'sales_channel_id' => str_repeat('a', 32)], str_repeat('b', 32), 2],
            [EcommercePlatform::Magento, ['store_url' => 'https://fietsen-utrecht.nl', 'access_token' => 'SYNTHETIC_magento_token', 'store_code' => 'default', 'store_id' => '1'], '4711', 1],
        ];
        Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/oauth/token') ? ['token_type' => 'Bearer', 'expires_in' => 600, 'access_token' => 'SYNTHETIC_shopware_token'] : ['data' => [], 'shop_urls' => [], 'generalInfo' => ['storeId' => 9999], 'id' => 'wrong-store', 'instance' => ['instanceId' => 'wrong-instance']]));
        foreach ($cases as [$platform,$input,$id,$calls]) {
            [$c] = app(ConnectionManager::class)->create($shop->memberships()->sole(), $platform, $input);
            $before = count(Http::recorded());
            try {
                app(ConnectorRegistry::class)->for($platform)->fetchOrder($c, $id);
                $this->fail('Wrong store accepted:'.$platform->value);
            } catch (ApiException $e) {
                $this->assertSame('provider_store_mismatch', $e->errorCode, $platform->value);
            }$this->assertSame($calls, count(Http::recorded()) - $before, $platform->value);
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/orders/') || str_contains($r->url(), '/api/search/order') || str_contains($r->url(), '/api/orders/'));
    }
}
