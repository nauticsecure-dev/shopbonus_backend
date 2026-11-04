<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\MagentoConnector;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Shopbonus\AsyncBridge\Model\Notification;
use Tests\TestCase;

class MagentoAsyncBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Webshop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('merchant-packages/common/Delivery.php');
        require_once base_path('merchant-packages/magento2/Model/Notification.php');
        $u = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'shop.example.com', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
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

    private function connection(bool $saas = false): WebshopConnection
    {
        return app(ConnectionManager::class)->create($this->shop->memberships()->sole()->setRelation('webshop', $this->shop), EcommercePlatform::Magento,
            ['deployment' => $saas ? 'saas' : 'self_hosted', 'saas_api_url' => $saas ? 'https://na1.api.commerce.adobe.com/SYNTHETIC_TENANT' : null, 'store_url' => 'https://shop.example.com', 'store_id' => '2', 'store_code' => 'default', 'access_token' => 'SYNTHETIC_native_token'])[0];
    }

    private function request(WebshopConnection $c, array $p): Request
    {
        $body = json_encode($p, JSON_THROW_ON_ERROR);
        $r = Request::create('/', 'POST', [], [], [], [], $body);
        foreach (\ShopbonusDelivery::headers($body, $c->credential('signing_secret'), str_repeat('a', 32), time()) as $header) {
            [$k,$v] = explode(': ', $header, 2);
            $r->headers->set($k, $v);
        }

        return $r;
    }

    public function test_packaged_payload_and_signer_trigger_exact_native_lookup_not_posted_financial_claims(): void
    {
        $c = $this->connection();
        $a = new MagentoConnector;
        $p = Notification::payload('2', 'default', '4711');
        $this->assertCount(4, $p);
        $p['order'] = ['id' => 'foreign', 'payment_status' => 'paid', 'refunded_minor' => 999999];
        $r = $this->request($c, $p);
        $event = $a->verifyIncomingEvent($c, $r);
        $this->assertSame(VerifiedEvent::KIND_ORDER_LOOKUP, $event->kind);
        $this->assertSame('4711', $a->notificationOrderId($c, $r->getContent()));
        $this->assertNull($a->notificationOrderId($c, json_encode(['store_id' => '3'] + $p)));
        $r->headers->set('X-Shopbonus-Signature', 't='.time().',v1='.str_repeat('0', 64));
        $this->expectException(InvalidWebhook::class);
        $a->verifyIncomingEvent($c, $r);
    }

    public function test_bad_scope_marker_ids_and_ping_cannot_request_native_reads(): void
    {
        $c = $this->connection();
        $a = new MagentoConnector;
        $good = Notification::payload('2', 'default', '4711');
        foreach ([['store_code' => 'foreign'], ['store_id' => 2], ['order_id' => '../4711'], ['shopbonus_magento_lookup_v1' => false]] as $bad) {
            $p = $bad + $good;
            $this->assertNull($a->notificationOrderId($c, json_encode($p)));
            try {
                $a->verifyIncomingEvent($c, $this->request($c, $p));
                $this->fail('Invalid native scope accepted.');
            } catch (InvalidWebhook) {
                $this->assertTrue(true);
            }
        }
        $r = $this->request($c, $good);
        $r->headers->set('X-Shopbonus-Topic', 'ping');
        $this->expectException(InvalidWebhook::class);
        $a->verifyIncomingEvent($c, $r);
    }

    public function test_saas_reads_scoped_tenant_endpoints_with_ims_bearer_and_store_header(): void
    {
        $c = $this->connection(true);
        Http::fake(function ($r) {
            $this->assertSame(['default'], $r->header('Store'));
            $this->assertSame(['Bearer SYNTHETIC_native_token'], $r->header('Authorization'));
            $this->assertStringStartsWith('https://na1.api.commerce.adobe.com/SYNTHETIC_TENANT/V1/', $r->url());
            $this->assertStringNotContainsString('/rest/', $r->url());
            if (str_contains($r->url(), 'store/storeConfigs')) {
                return Http::response([['id' => 2, 'code' => 'default', 'secure_base_url' => 'https://shop.example.com/']]);
            }
            if (str_ends_with($r->url(), '/orders/4711')) {
                return Http::response(['entity_id' => 4711, 'store_id' => 2, 'order_currency_code' => 'EUR', 'grand_total' => '10.00', 'state' => 'new']);
            }

            return Http::response(['items' => [], 'total_count' => 0]);
        });
        $a = new MagentoConnector;
        $p = $a->fetchOrder($c, '4711');
        $this->assertSame(4711, $p['magento_order']['entity_id']);
        Http::assertSentCount(5);
        $n = $a->normalizeOrder($p);
        $this->assertNull($n->paidAt);
        $this->assertSame(0, $n->refundedMinor);
    }

    public function test_saas_foreign_store_config_stops_before_order_reads(): void
    {
        $c = $this->connection(true);
        Http::fake(fn () => Http::response([['id' => 3, 'code' => 'foreign', 'secure_base_url' => 'https://foreign.example.com/']]));
        try {
            (new MagentoConnector)->fetchOrder($c, '4711');
            $this->fail('Foreign store accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_store_mismatch', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_module_notification_ids_and_retry_delay_are_bounded(): void
    {
        $this->assertSame(30, Notification::delay(0));
        $this->assertSame(3600, Notification::delay(100));
        $this->expectException(\InvalidArgumentException::class);
        Notification::payload('2', 'all/foreign', '4711');
    }
}
