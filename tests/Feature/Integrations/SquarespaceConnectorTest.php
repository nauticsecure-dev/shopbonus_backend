<?php

namespace Tests\Feature\Integrations;

use App\Enums\ConnectionStatus;
use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Connectors\SquarespaceConnector;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Services\Onboarding\Network\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SquarespaceConnectorTest extends TestCase
{
    use RefreshDatabase;

    private WebshopConnection $connection;

    private const SITE = '5863f6faf7e0abc3cf95ce3a';

    private const ORDER = '585d498fdee9f31a60284a37';

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $shop = Webshop::factory()->withMember($user)->create(['created_by' => $user->id, 'verified_domain' => 'fietsen-utrecht.nl', 'ownership_verified_at' => now(), 'ownership_method' => 'dns_txt']);
        [$this->connection] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::Squarespace, ['website_id' => self::SITE, 'api_key' => 'SYNTHETIC_squarespace_key']);
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

    private function order(): array
    {
        return ['id' => self::ORDER, 'orderNumber' => '3', 'channel' => 'web', 'testmode' => false,
            'paymentState' => 'PAID', 'fulfillmentStatus' => 'PENDING', 'grandTotal' => ['value' => '57.70', 'currency' => 'EUR'],
            'refundedTotal' => ['value' => '0.00', 'currency' => 'EUR'], 'customerEmail' => 'synthetic@example.test',
            'billingAddress' => ['firstName' => 'Ada'], 'createdOn' => '2026-11-01T10:00:00Z', 'modifiedOn' => '2026-11-01T11:00:00Z'];
    }

    private function fake(array $response, ?array $site = null, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/authorization/website')
            ? ($site ?? ['id' => self::SITE, 'url' => 'https://fietsen-utrecht.nl']) : $response, $status));
    }

    public function test_native_read_uses_fixed_host_identity_and_exact_money_without_inventing_paid_time(): void
    {
        $this->fake($this->order());
        $adapter = app(ConnectorRegistry::class)->for(EcommercePlatform::Squarespace);
        $order = $adapter->normalizeOrder($adapter->fetchOrder($this->connection, self::ORDER));
        $this->assertSame(5770, $order->amountMinor);
        $this->assertSame(PaymentStatus::Paid, $order->paymentStatus);
        $this->assertNull($order->paidAt);
        $this->assertTrue($order->isComplete());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.squarespace.com/1.0/commerce/orders/'.self::ORDER && $request->hasHeader('Authorization', 'Bearer SYNTHETIC_squarespace_key'));
        $this->assertSame(ConnectionStatus::SetupRequired, $this->connection->fresh()->status);
    }

    public function test_identity_mismatch_prevents_order_read(): void
    {
        $this->fake($this->order(), ['id' => self::SITE, 'url' => 'https://example.com']);
        try {
            app(SquarespaceConnector::class)->fetchOrder($this->connection, self::ORDER);
            $this->fail('A different storefront must be refused.');
        } catch (ApiException $exception) {
            Http::assertSentCount(1);
            $this->assertStringContainsString('canonical', $exception->getMessage());
        }
    }

    public function test_testmode_missing_mode_and_wrong_order_are_refused(): void
    {
        foreach ([['testmode' => true], ['testmode' => null], ['channel' => 'pos'], ['id' => str_repeat('a', 24)]] as $change) {
            $this->fake(array_replace($this->order(), $change));
            try {
                app(SquarespaceConnector::class)->fetchOrder($this->connection, self::ORDER);
                $this->fail('Unqualified order must be refused.');
            } catch (ApiException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_fulfillment_is_not_payment_and_refunds_use_matching_currency(): void
    {
        $adapter = app(SquarespaceConnector::class);
        foreach (['AUTHORIZED', 'NOT_CHARGED', 'PENDING', 'PARTIALLY_PAID', 'FAILED', 'REFUND_PENDING', 'REFUND_FAILED', 'new_state'] as $state) {
            $order = $adapter->normalizeOrder(['native_order' => array_replace($this->order(), ['paymentState' => $state, 'fulfillmentStatus' => 'FULFILLED'])]);
            $this->assertNotSame(PaymentStatus::Paid, $order->paymentStatus);
            $this->assertNull($order->paidAt);
        }
        $partial = array_replace($this->order(), ['refundedTotal' => ['value' => '5.25', 'currency' => 'EUR']]);
        $order = $adapter->normalizeOrder(['native_order' => $partial]);
        $this->assertSame(525, $order->refundedMinor);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->paymentStatus);
        $partial['refundedTotal']['currency'] = 'USD';
        $this->assertNull($adapter->normalizeOrder(['native_order' => $partial])->refundedMinor);
    }

    public function test_cursor_pages_never_send_dates_or_follow_remote_urls(): void
    {
        $this->fake(['result' => [['id' => self::ORDER]], 'pagination' => ['hasNextPage' => true, 'nextPageCursor' => 'next==', 'nextPageUrl' => 'https://attacker.example']]);
        $adapter = app(SquarespaceConnector::class);
        $since = CarbonImmutable::parse('2026-11-01T10:00:00Z');
        $page = $adapter->discoverOrders($this->connection, $since, $since->addDay(), null);
        $this->assertSame(['ids' => [self::ORDER], 'next' => 'next=='], $page);
        $this->fake(['result' => [], 'pagination' => ['hasNextPage' => false]]);
        $this->assertNull($adapter->discoverOrders($this->connection, $since, $since->addDay(), 'next==')['next']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.squarespace.com/1.0/commerce/orders?cursor=next%3D%3D');
    }

    public function test_empty_nonterminal_page_is_rejected(): void
    {
        $this->fake(['result' => [], 'pagination' => ['hasNextPage' => true, 'nextPageCursor' => 'next']]);
        $this->expectException(ApiException::class);
        app(SquarespaceConnector::class)->discoverOrders($this->connection, CarbonImmutable::now()->subDay(), CarbonImmutable::now(), null);
    }

    public function test_assignment_token_comes_only_from_one_exact_checkout_form_row(): void
    {
        $adapter = new SquarespaceConnector;
        $token = 'bx_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $with = fn (array $rows) => $adapter->normalizeOrder(['native_order' => array_replace($this->order(), ['formSubmission' => $rows])])->bannerAssignmentToken;
        $this->assertSame($token, $with([['label' => 'How did you hear about us?', 'value' => 'Facebook'], ['label' => 'shopbonus_assignment_token', 'value' => $token]]));
        $this->assertNull($with([['label' => 'shopbonus_assignment_token', 'value' => $token], ['label' => 'shopbonus_assignment_token', 'value' => $token]]));
        $this->assertNull($with([['label' => 'shopbonus_assignment_token', 'value' => strtoupper($token)]]));
        $this->assertNull($with([['label' => 'How did you hear about us?', 'value' => $token]]));
        $this->assertNull($adapter->normalizeOrder(['native_order' => $this->order()])->bannerAssignmentToken);
    }

    public function test_api_key_connection_accepts_no_webhook(): void
    {
        $this->expectException(InvalidWebhook::class);
        app(SquarespaceConnector::class)->verifyIncomingEvent($this->connection, Request::create('/', 'POST'));
    }
}
