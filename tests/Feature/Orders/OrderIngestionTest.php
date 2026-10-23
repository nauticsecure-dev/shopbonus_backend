<?php

namespace Tests\Feature\Orders;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\WebhookEventStatus;
use App\Events\OrderSheetUpdated;
use App\Integrations\Data\PaymentStatus;
use App\Models\AuditEvent;
use App\Models\OrderEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopMembership;
use App\Models\WebshopOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

/**
 * B05: order ingestion, state transitions and the Order Sheet (Trello #1, #3 B.6–B.7).
 */
class OrderIngestionTest extends TestCase
{
    use RefreshDatabase, SignsWebhooks;

    private User $owner;

    private Webshop $shop;

    private string $secret;

    private string $path;

    private WebshopConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id, 'name' => 'Fietsen']);
        Sanctum::actingAs($this->owner);
        [$this->connection, $this->secret, $this->path] = $this->connectPlatform('woocommerce');
    }

    /** @return array{0: WebshopConnection, 1: string, 2: string} */
    private function connectPlatform(string $platform, ?Webshop $shop = null, array $input = []): array
    {
        $shop ??= $this->shop;
        $r = $this->postJson("/api/v1/workspaces/{$shop->public_id}/connections", ['platform' => $platform, ...$input])->assertCreated();

        return [WebshopConnection::where('public_id', $r->json('data.id'))->sole(), (string) collect($r->json('data.secrets'))->first(),
            (string) parse_url($r->json('data.setup.webhook_url'), PHP_URL_PATH)];
    }

    private function deliver(array $order, string $delivery, ?string $path = null, ?string $secret = null): TestResponse
    {
        $body = json_encode($order);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->wooHeaders($body, $secret ?? $this->secret, $delivery) as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', $path ?? $this->path, [], [], [], $server, $body);
    }

    private function url(string $suffix = '', ?Webshop $shop = null): string
    {
        return '/api/v1/workspaces/'.($shop ?? $this->shop)->public_id.$suffix;
    }

    // ---------------------------------------------------------------- ingestion

    public function test_card3_f3_verified_paid_order_appears_in_the_order_sheet(): void
    {
        Event::fake([OrderSheetUpdated::class]);
        $this->deliver($this->wooOrder(), 'd-1')->assertStatus(202);

        $order = WebshopOrder::sole();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(5995, $order->amount_minor);
        $this->assertSame('complete', $order->ingestion_status);
        $this->assertTrue($order->has_phone);
        $this->assertStringNotContainsString('anna@example.nl', (string) DB::table('webshop_orders')->value('customer_email'));

        $row = $this->getJson($this->url('/orders'))->assertOk()->json('data.0');
        $this->assertSame('4711', $row['reference']);
        $this->assertSame('anna@example.nl', $row['customer']['email']);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertSame('not_evaluated', $row['eligibility_status']);
        $this->assertSame('not_evaluated', $row['contact_status']);
        $this->assertTrue(WebhookEvent::sole()->result['ingested']);
        Event::assertDispatched(OrderSheetUpdated::class, fn ($e) => $e->orderId === $order->public_id && $e->broadcastWith() === ['order_id' => $order->public_id, 'payment_status' => 'paid']);
    }

    public function test_card1_e2e5_and_card3_f5_duplicate_and_retried_events_produce_one_order(): void
    {
        $this->deliver($this->wooOrder(), 'd-1')->assertStatus(202);
        $this->deliver($this->wooOrder(), 'd-1')->assertOk();               // same delivery retried
        $this->deliver($this->wooOrder(), 'd-2')->assertStatus(202);        // same order, new delivery (order.updated)

        $this->assertSame(1, WebshopOrder::count());
        $this->assertSame(['created', 'updated'], OrderEvent::orderBy('id')->pluck('type')->all());
    }

    public function test_card1_e2e4_incomplete_order_imported_but_flagged_and_no_phone_is_not_callable(): void
    {
        $this->deliver($this->wooOrder(['id' => 1, 'billing' => ['email' => '']]), 'd-1');
        $this->deliver($this->wooOrder(['id' => 2, 'billing' => ['phone' => '']]), 'd-2');

        $incomplete = WebshopOrder::where('external_order_id', '1')->sole();
        $this->assertSame('incomplete', $incomplete->ingestion_status);
        $this->assertSame(['email'], $incomplete->missing_fields);

        $noPhone = WebshopOrder::where('external_order_id', '2')->sole();
        $this->assertSame('complete', $noPhone->ingestion_status);
        $this->assertFalse($noPhone->has_phone);

        $this->assertSame(1, $this->getJson($this->url('/orders?ingestion_status=incomplete'))->json('meta.total'));
        $this->assertFalse($this->getJson($this->url('/orders/'.$noPhone->public_id))->json('data.callable'));
    }

    public function test_payment_lifecycle_pending_to_paid_to_refunded(): void
    {
        $this->deliver($this->wooOrder(['status' => 'pending', 'date_paid_gmt' => null, 'date_modified_gmt' => '2026-10-23T10:00:00']), 'd-1');
        $this->assertSame(PaymentStatus::Pending, WebshopOrder::sole()->payment_status);

        $this->deliver($this->wooOrder(['date_modified_gmt' => '2026-10-23T10:05:00']), 'd-2');
        $this->assertSame(PaymentStatus::Paid, WebshopOrder::sole()->payment_status);

        $this->deliver($this->wooOrder(['status' => 'refunded', 'date_modified_gmt' => '2026-10-24T09:00:00', 'refunds' => [['id' => 9, 'total' => '-59.95']]]), 'd-3');
        $order = WebshopOrder::sole();
        $this->assertSame(PaymentStatus::Refunded, $order->payment_status);
        $this->assertSame(5995, $order->refunded_minor);
        $this->assertSame(['created', 'payment_changed', 'payment_changed'], OrderEvent::orderBy('id')->pluck('type')->all());
    }

    public function test_out_of_order_older_event_never_overwrites_newer_state(): void
    {
        // The refund (newer) arrives before the earlier "paid" event.
        $this->deliver($this->wooOrder(['status' => 'refunded', 'date_modified_gmt' => '2026-10-24T09:00:00']), 'd-2');
        $this->deliver($this->wooOrder(['date_modified_gmt' => '2026-10-23T10:05:00']), 'd-1');

        $this->assertSame(PaymentStatus::Refunded, WebshopOrder::sole()->payment_status);
        $stale = OrderEvent::where('type', 'ignored_stale')->sole();
        $this->assertSame('older_than_stored', $stale->summary['reason']);
        $this->assertFalse(WebhookEvent::latest('id')->first()->result['ingested']); // the late event was not applied
    }

    public function test_without_timestamps_cancelled_or_refunded_never_revert_to_paid(): void
    {
        $this->deliver($this->wooOrder(['status' => 'cancelled', 'date_modified_gmt' => null]), 'd-1');
        $this->deliver($this->wooOrder(['date_modified_gmt' => null]), 'd-2'); // late "paid" with no ordering info

        $this->assertSame(PaymentStatus::Cancelled, WebshopOrder::sole()->payment_status);
        $this->assertSame('not_forward', OrderEvent::where('type', 'ignored_stale')->sole()->summary['reason']);
    }

    public function test_redacted_later_event_does_not_erase_known_fields(): void
    {
        $this->deliver($this->wooOrder(['date_modified_gmt' => '2026-10-23T10:00:00']), 'd-1');
        $this->deliver($this->wooOrder(['date_modified_gmt' => '2026-10-23T11:00:00', 'billing' => ['email' => '', 'phone' => '']]), 'd-2');

        $order = WebshopOrder::sole();
        $this->assertSame('anna@example.nl', $order->customer_email);
        $this->assertTrue($order->has_phone);
        $this->assertSame('complete', $order->ingestion_status);
    }

    public function test_shopify_refund_notification_marks_paid_order_partially_refunded(): void
    {
        [, , $path] = $this->connectPlatform('shopify', null, ['shop_domain' => 'fietsen.myshopify.com', 'signing_secret' => 'shpss_secret_for_tests']);
        $send = function (array $payload, string $id, string $topic) use ($path) {
            $body = json_encode($payload);
            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
            foreach ($this->shopifyHeaders($body, 'shpss_secret_for_tests', $id, $topic) as $k => $v) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
            }

            return $this->call('POST', $path, [], [], [], $server, $body);
        };

        $send($this->shopifyOrder(), 'w-1', 'orders/paid')->assertStatus(202);
        $send(['id' => 555, 'order_id' => 820982911946154508, 'created_at' => '2026-10-24T10:00:00Z'], 'w-2', 'refunds/create')->assertStatus(202);
        $send(['id' => 556, 'order_id' => 1, 'created_at' => '2026-10-24T10:00:00Z'], 'w-3', 'refunds/create')->assertStatus(202); // unknown order

        $order = WebshopOrder::where('platform', 'shopify')->sole();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->payment_status);
        $this->assertSame(1, OrderEvent::where('type', 'refund_notified')->count());
        $this->assertSame('order_not_found', WebhookEvent::where('dedupe_key', 'webhook:w-3')->sole()->result['reason']);
    }

    public function test_reconnecting_a_platform_does_not_duplicate_orders(): void
    {
        $this->deliver($this->wooOrder(), 'd-1');
        $this->deleteJson($this->url("/connections/{$this->connection->public_id}"))->assertOk();
        [, $secret, $path] = $this->connectPlatform('woocommerce');

        $this->deliver($this->wooOrder(['date_modified_gmt' => '2026-10-23T12:00:00']), 'd-1', $path, $secret);

        $this->assertSame(1, WebshopOrder::count());
    }

    // ---------------------------------------------------------------- Order Sheet API

    public function test_order_sheet_filters_search_sort_and_today_counters(): void
    {
        $this->travelTo(now()->setTimezone('Europe/Amsterdam')->setTime(14, 0)->utc());
        $today = now()->utc()->format('Y-m-d\TH:i:s');
        $this->deliver($this->wooOrder(['id' => 1, 'number' => 'A-100', 'total' => '10.00', 'date_created_gmt' => $today]), 'd-1');
        $this->deliver($this->wooOrder(['id' => 2, 'number' => 'A-200', 'total' => '30.00', 'date_created_gmt' => $today, 'billing' => ['email' => 'bob@example.nl']]), 'd-2');
        $this->deliver($this->wooOrder(['id' => 3, 'number' => 'B-300', 'status' => 'pending', 'date_paid_gmt' => null, 'total' => '99.00', 'date_created_gmt' => $today]), 'd-3');

        $sheet = $this->getJson($this->url('/orders?sort=-amount_minor'))->assertOk();
        $this->assertSame(['B-300', 'A-200', 'A-100'], array_column($sheet->json('data'), 'reference'));
        $this->assertSame(3, $sheet->json('meta.today.orders'));
        $this->assertSame(2, $sheet->json('meta.today.paid_orders'));
        $this->assertSame(['EUR' => 4000], $sheet->json('meta.today.paid_revenue_minor'));

        $this->assertSame(['A-100', 'A-200'], array_column($this->getJson($this->url('/orders?search=A-&sort=placed_at'))->json('data'), 'reference'));
        $this->assertSame(['A-200'], array_column($this->getJson($this->url('/orders?search=BOB@example.nl'))->json('data'), 'reference'));
        $this->assertSame(['B-300'], array_column($this->getJson($this->url('/orders?payment_status=pending'))->json('data'), 'reference'));
        $this->assertSame(2, count($this->getJson($this->url('/orders?per_page=2'))->json('data')));
        $this->getJson($this->url('/orders?sort=customer_email'))->assertStatus(422);
    }

    public function test_orders_are_never_visible_across_webshops(): void
    {
        $this->deliver($this->wooOrder(), 'd-1');
        $order = WebshopOrder::sole();

        $other = User::factory()->create();
        $otherShop = Webshop::factory()->withMember($other)->create(['created_by' => $other->id]);
        Sanctum::actingAs($other);

        $this->assertSame([], $this->getJson($this->url('/orders', $otherShop))->assertOk()->json('data'));
        $this->getJson($this->url('/orders/'.$order->public_id, $otherShop))->assertNotFound();
        $this->getJson($this->url('/orders'))->assertNotFound(); // the first webshop itself
    }

    public function test_order_detail_has_sanitised_history(): void
    {
        $this->deliver($this->wooOrder(), 'd-1');
        $detail = $this->getJson($this->url('/orders/'.WebshopOrder::sole()->public_id))->assertOk();

        $this->assertSame('created', $detail->json('history.0.type'));
        $this->assertStringNotContainsString('anna@example.nl', json_encode($detail->json('history')));
    }

    public function test_export_requires_permission_is_audited_and_injection_safe(): void
    {
        $this->deliver($this->wooOrder(['billing' => ['first_name' => '=HYPERLINK("http://evil")', 'email' => '+cmd@example.nl']]), 'd-1');

        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'permissions' => ['orders.view'], 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->getJson($this->url('/orders'))->assertOk();
        $this->get($this->url('/orders/export'), ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('error.details.required_permission', 'orders.export');

        Sanctum::actingAs($this->owner);
        $csv = $this->get($this->url('/orders/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+cmd@example.nl", $csv);
        $this->assertStringContainsString(',59.95,EUR,paid,', $csv);
        $this->assertSame(1, AuditEvent::where('action', 'orders.exported')->count());
    }

    public function test_failed_event_can_be_retried(): void
    {
        $body = '{not json';
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->wooHeaders($body, $this->secret, 'bad-1') as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $this->call('POST', $this->path, [], [], [], $server, $body)->assertStatus(202);
        $event = WebhookEvent::sole();
        $this->assertSame(WebhookEventStatus::Failed, $event->status);

        // Fix the stored payload (as an operator would after a mapping bug) and retry.
        $event->forceFill(['payload' => json_encode($this->wooOrder())])->save();
        $this->postJson($this->url("/connections/{$this->connection->public_id}/events/{$event->public_id}/retry"))->assertStatus(202);

        $this->assertSame(WebhookEventStatus::Processed, $event->fresh()->status);
        $this->assertSame(1, WebshopOrder::count());
        $this->postJson($this->url("/connections/{$this->connection->public_id}/events/{$event->public_id}/retry"))
            ->assertStatus(409)->assertJsonPath('error.code', 'event_not_retryable');
    }

    public function test_broadcast_channel_authorisation(): void
    {
        $authorise = Broadcast::driver()->getChannels()['webshops.{webshopPublicId}.orders'];
        $this->assertTrue($authorise($this->owner, $this->shop->public_id));

        $stranger = User::factory()->create();
        $this->assertFalse($authorise($stranger, $this->shop->public_id));
        $this->assertFalse($authorise($this->owner, (string) str()->ulid()));

        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $this->assertFalse($authorise($employee, $this->shop->public_id)); // no orders.view

        WebshopMembership::where('user_id', $employee->id)->update(['permissions' => ['orders.view']]);
        $this->assertTrue($authorise($employee, $this->shop->public_id));

        $this->shop->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse($authorise($this->owner, $this->shop->fresh()->public_id));

        // The auth endpoint itself requires authentication.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/broadcasting/auth', ['channel_name' => 'private-x', 'socket_id' => '1.2'])->assertUnauthorized();
    }
}
