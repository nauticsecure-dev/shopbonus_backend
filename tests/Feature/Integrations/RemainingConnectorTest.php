<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\CapabilityMatrix;
use App\Integrations\ConnectionManager;
use App\Integrations\ConnectionReadiness;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Connectors\CcvConnector;
use App\Integrations\Connectors\MijnwebwinkelConnector;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

class RemainingConnectorTest extends TestCase
{
    use RefreshDatabase, SignsWebhooks;

    private Webshop $shop;

    private array $native;

    private bool $wrongStore = false;

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
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
        $this->native = ['id' => 4711, 'is_completed' => true, 'ordernumber_full' => 'SHOP-4711', 'paid' => true, 'status' => 7,
            'create_date' => '2026-11-01T12:00:00Z', 'currency' => 'EUR', 'total_price' => '59.95',
            'customer' => ['email' => 'synthetic@example.test', 'billingaddress' => ['first_name' => 'Synthetic', 'telephone' => '+31612345678']]];
        Http::fake(function (Request $r) {
            $uri = parse_url($r->url(), PHP_URL_PATH);
            $query = parse_url($r->url(), PHP_URL_QUERY);
            $signed = $uri.($query === null ? '' : '?'.$query);
            $time = $r->header('x-date')[0];
            $this->assertSame(hash_hmac('sha512', 'SYNTHETIC_public_key|GET|'.$signed.'||'.$time, 'SYNTHETIC_secret_key'), $r->header('x-hash')[0]);
            if ($this->status !== 200) {
                return Http::response([], $this->status, ['Retry-After' => '60']);
            }
            $body = match ($uri) {
                '/api/rest/v1/webshops/12/' => ['id' => $this->wrongStore ? 99 : 12, 'is_multishop_system' => false, 'is_salespos' => false, 'api_root' => 'https://shop.example.com/api/rest/v1/'],
                '/api/rest/v1/webshops/12/domains/' => ['items' => [['domainname' => 'shop.example.com', 'ssldomain' => true]]],
                '/api/rest/v1/orders/4711/' => $this->native,
                '/api/rest/v1/orders/' => ['start' => 0, 'size' => 25, 'items' => [$this->native], 'next' => null],
                default => throw new \RuntimeException('Unexpected CCV operation.'),
            };

            return Http::response($body);
        });
    }

    private function connection(): WebshopConnection
    {
        return app(ConnectionManager::class)->create($this->shop->memberships()->sole(), EcommercePlatform::Ccv,
            ['store_url' => 'https://shop.example.com', 'webshop_id' => '12', 'api_key' => 'SYNTHETIC_public_key', 'api_secret' => 'SYNTHETIC_secret_key'])[0];
    }

    public function test_ccv_native_lookup_verifies_identity_and_preserves_unknown_payment_evidence(): void
    {
        $c = $this->connection();
        $adapter = app(CcvConnector::class);
        $o = $adapter->normalizeOrder($adapter->fetchOrder($c, '4711'));
        $this->assertTrue($o->isComplete());
        $this->assertSame(5995, $o->amountMinor);
        $this->assertSame(PaymentStatus::Paid, $o->paymentStatus);
        $this->assertNull($o->paidAt);
        $this->assertNull($o->refundedMinor);
        $this->assertNull($o->providerUpdatedAt);
        $this->assertNull($o->contactConsent);
        $caps = app(CapabilityMatrix::class)->forPlatform(EcommercePlatform::Ccv);
        $this->assertSame('LIMITED', $caps['payments']['state']);
        $this->assertSame('LIMITED', $caps['backfill']['state']);
        $this->assertSame('UNSUPPORTED', $caps['webhooks']['state']);
        $this->assertContains(false, app(ConnectionReadiness::class)->gates($c));
        Http::assertSentCount(3);
    }

    public function test_wrong_store_stops_before_order_read(): void
    {
        $this->wrongStore = true;
        try {
            app(CcvConnector::class)->fetchOrder($this->connection(), '4711');
            $this->fail('Foreign store accepted.');
        } catch (ApiException $e) {
            $this->assertSame('provider_store_mismatch', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_creation_window_paging_is_bounded_and_signed(): void
    {
        $page = app(CcvConnector::class)->discoverOrders($this->connection(), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-02'), null);
        $this->assertSame(['ids' => ['4711'], 'next' => null], $page);
        Http::assertSent(fn (Request $r) => ($r['min_create_date'] ?? null) === '2026-11-01' && (string) ($r['size'] ?? '') === '25');
    }

    public function test_ccv_throttling_remains_credential_bound_across_changing_signatures(): void
    {
        $c = $this->connection();
        $this->status = 429;
        try {
            app(CcvConnector::class)->fetchOrder($c, '4711');
            $this->fail('Rate limit ignored.');
        } catch (ApiException $e) {
            $this->assertSame('provider_rate_limited', $e->errorCode);
        }
        $this->travel(2)->seconds();
        $this->status = 200;
        try {
            app(CcvConnector::class)->fetchOrder($c, '4711');
            $this->fail('Cooldown ignored.');
        } catch (ApiException $e) {
            $this->assertSame('provider_circuit_open', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_paused_and_unverified_store_block_egress(): void
    {
        $c = $this->connection();
        $c->forceFill(['paused_at' => now()])->save();
        try {
            app(CcvConnector::class)->fetchOrder($c, '4711');
            $this->fail('Pause ignored.');
        } catch (ApiException $e) {
            $this->assertSame('connection_paused', $e->errorCode);
        }
        $c->forceFill(['paused_at' => null])->save();
        $this->shop->forceFill(['ownership_verified_at' => null])->save();
        $c->unsetRelation('webshop');
        $this->expectException(ApiException::class);
        app(CcvConnector::class)->fetchOrder($c, '4711');
    }

    public function test_incomplete_or_foreign_order_never_normalizes_as_verified_source(): void
    {
        $c = $this->connection();
        $this->native['id'] = 999;
        $this->expectException(ApiException::class);
        app(CcvConnector::class)->fetchOrder($c, '4711');
    }

    public function test_mijnwebwinkel_bridge_requires_signature_and_exact_store_context(): void
    {
        [$c,$secret] = app(ConnectionManager::class)->create($this->shop->memberships()->sole(), EcommercePlatform::Mijnwebwinkel,
            ['store_domain' => 'shop.example.com', 'merchant_reference' => 'MWW-12']);
        $adapter = app(ConnectorRegistry::class)->for(EcommercePlatform::Mijnwebwinkel);
        $this->assertInstanceOf(MijnwebwinkelConnector::class, $adapter);
        $p = $this->genericOrder(['bridge' => ['store_domain' => 'shop.example.com', 'merchant_reference' => 'MWW-12']]);
        $body = json_encode($p);
        $request = \Illuminate\Http\Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        foreach ($this->genericHeaders($body, $secret['signing_secret']) as $k => $v) {
            $request->headers->set($k, $v);
        }
        $this->assertSame('order.paid', $adapter->verifyIncomingEvent($c, $request)->topic);
        $o = $adapter->normalizeOrder($p);
        $this->assertNull($o->paidAt);
        $this->assertNull($o->refundedMinor);
        $this->assertNull($o->contactConsent);
        $this->assertContains(false, app(ConnectionReadiness::class)->gates($c));
        $p['bridge']['merchant_reference'] = 'FOREIGN';
        $body = json_encode($p);
        $request = \Illuminate\Http\Request::create('/', 'POST', content: $body);
        foreach ($this->genericHeaders($body, $secret['signing_secret']) as $k => $v) {
            $request->headers->set($k, $v);
        }
        $this->expectException(InvalidWebhook::class);
        $adapter->verifyIncomingEvent($c, $request);
    }
}
