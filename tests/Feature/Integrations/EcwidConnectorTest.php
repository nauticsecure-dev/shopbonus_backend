<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectionManager;
use App\Integrations\Connectors\EcwidConnector;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\RecoverProviderOrder;
use App\Models\IntegrationOrderRecovery;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcwidConnectorTest extends TestCase
{
    use RefreshDatabase;

    private WebshopConnection $c;

    protected function setUp(): void
    {
        parent::setUp();
        $u = User::factory()->create();
        $s = Webshop::factory()->withMember($u)->create(['created_by' => $u->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        [$this->c] = app(ConnectionManager::class)->create($s->memberships()->sole(), EcommercePlatform::Ecwid, ['store_id' => '1003', 'access_token' => 'secret_SYNTHETIC_token', 'client_secret' => 'SYNTHETIC_ecwid_app_secret']);
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
    }

    private function notification(): array
    {
        return ['eventId' => '80aece08-40e8-4145-8764-6c2f0d38678', 'eventCreated' => now()->getTimestamp(), 'storeId' => 1003, 'entityId' => 4711, 'eventType' => 'order.created', 'data' => ['newPaymentStatus' => 'PAID']];
    }

    private function request(array $p, ?string $secret = null): Request
    {
        $r = Request::create('/', 'POST', [], [], [], [], json_encode($p));
        $r->headers->set('X-Ecwid-Webhook-Signature', base64_encode(hash_hmac('sha256', $p['eventCreated'].'.'.$p['eventId'], $secret ?? 'SYNTHETIC_ecwid_app_secret', true)));

        return $r;
    }

    public function test_documented_signature_is_notification_only_and_bad_secret_is_rejected(): void
    {
        $p = $this->notification();
        $adapter = app(EcwidConnector::class);
        $this->assertSame(VerifiedEvent::KIND_ORDER_LOOKUP, $adapter->verifyIncomingEvent($this->c, $this->request($p))->kind);
        $this->expectException(InvalidWebhook::class);
        $adapter->verifyIncomingEvent($this->c, $this->request($p, 'wrong'));
    }

    public function test_wrong_store_is_rejected_even_with_valid_signature(): void
    {
        $p = $this->notification();
        $p['storeId'] = 2004;
        $this->expectException(InvalidWebhook::class);
        app(EcwidConnector::class)->verifyIncomingEvent($this->c, $this->request($p));
    }

    public function test_webhook_queues_native_lookup_without_trusting_claimed_payment_and_is_idempotent(): void
    {
        Bus::fake([ProcessWebhookEvent::class, RecoverProviderOrder::class]);
        $p = $this->notification();
        $body = json_encode($p);
        $url = '/api/v1/webhooks/ecwid/'.$this->c->webhook_token;
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ECWID_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $p['eventCreated'].'.'.$p['eventId'], 'SYNTHETIC_ecwid_app_secret', true))];
        $this->call('POST', $url, [], [], [], $headers, $body)->assertSuccessful();
        $event = $this->c->events()->sole();
        $this->app->call([new ProcessWebhookEvent($event->id), 'handle']);
        $this->app->call([new ProcessWebhookEvent($event->id), 'handle']);
        $this->assertSame(1, IntegrationOrderRecovery::count());
        $this->assertSame('reconciliation', IntegrationOrderRecovery::sole()->purpose);
        $this->assertSame(0, WebshopOrder::count());
        Http::assertNothingSent();
    }

    public function test_native_identity_and_plan_gate_precede_order_read_and_currency_is_never_invented(): void
    {
        Http::fake(fn ($r) => Http::response(str_contains($r->url(), '/profile') ? ['generalInfo' => ['storeId' => 1003, 'storeUrl' => 'https://fietsen-utrecht.nl'], 'account' => ['suspended' => false, 'availableFeatures' => ['API']]] : ['id' => 'K8XTQ', 'internalId' => 4711, 'paymentStatus' => 'PAID', 'total' => '10.00', 'vendorOrderNumber' => 'K8XTQ', 'createDate' => '2026-10-28 10:00:00 +0000']));
        $a = app(EcwidConnector::class);
        $o = $a->normalizeOrder($a->fetchOrder($this->c, '4711'));
        $this->assertSame('4711', $o->externalId);
        $this->assertNull($o->amountMinor);
        $this->assertNull($o->currency);
        $this->assertNull($o->paidAt);
        $this->assertFalse($o->isComplete());
        Http::assertSentCount(2);
    }
}
