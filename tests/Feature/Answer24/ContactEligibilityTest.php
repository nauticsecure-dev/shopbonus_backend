<?php

namespace Tests\Feature\Answer24;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Models\AuditEvent;
use App\Models\ContactOpportunity;
use App\Models\ContactSuppression;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopOrder;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

/**
 * B07a: Answer24 eligibility, one-hour grouping and cancellation
 * (Trello #1 E2E 4, 6, 7, 8; #3 F4, F6, F7).
 */
class ContactEligibilityTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase, SignsWebhooks;

    private User $owner;

    private Webshop $shop;

    private string $secret;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        Sanctum::actingAs($this->owner);

        $membership = $this->shop->memberships()->sole()->setRelation('webshop', $this->shop)->setRelation('user', $this->owner);
        $offers = app(OfferService::class);
        $offers->transition($offers->create($membership, OfferAcquisition::MerchantReward, [
            'name' => '€10 korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 90,
        ]), OfferStatus::Active);

        $this->putJson($this->url('/answer24/settings'), ['enabled' => true, 'lawful_basis' => 'consent_at_checkout', 'attestation' => true])->assertOk();

        $r = $this->postJson($this->url('/connections'), ['platform' => 'prestashop'])->assertCreated();
        $this->secret = $r->json('data.secrets.signing_secret');
        $this->path = (string) parse_url($r->json('data.setup.webhook_url'), PHP_URL_PATH);
    }

    private function url(string $suffix): string
    {
        return "/api/v1/workspaces/{$this->shop->public_id}{$suffix}";
    }

    private function order(string $id, array $overrides = [], ?string $eventId = null): TestResponse
    {
        $body = json_encode($this->genericOrder(['order' => array_replace_recursive([
            'id' => $id, 'reference' => "PS-{$id}", 'total_minor' => 7500, 'updated_at' => now()->toIso8601String(),
            'customer' => ['first_name' => 'Anna', 'email' => 'anna@example.nl', 'phone' => '06 1234 5678', 'contact_consent' => true],
        ], $overrides)]));
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->genericHeaders($body, $this->secret, $eventId ?? 'e-'.$id.'-'.uniqid()) as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', $this->path, [], [], [], $server, $body);
    }

    private function orderRow(string $externalId): WebshopOrder
    {
        return WebshopOrder::where('external_order_id', $externalId)->sole();
    }

    public function test_paid_consented_order_with_phone_is_grouped_then_queued_after_the_window(): void
    {
        $this->order('1001')->assertStatus(202);

        $order = $this->orderRow('1001');
        $this->assertSame('eligible', $order->eligibility_status);
        $this->assertSame('grouped', $order->contact_status);
        $opportunity = ContactOpportunity::sole();
        $this->assertSame('pending', $opportunity->status);
        $this->assertSame(0, OutboxMessage::count());

        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows')->assertSuccessful();

        $this->assertSame('ready', $opportunity->fresh()->status);
        $this->assertSame('queued', $order->fresh()->contact_status);
        $message = OutboxMessage::sole();
        $this->assertSame('answer24.contact_requested', $message->type);
        $this->assertSame('pending', $message->status);
        $this->assertSame('+31612345678', $message->payload['customer']['phone']);
        $this->assertSame(['PS-1001'], array_column($message->payload['orders'], 'reference'));
        $this->assertSame('consent_at_checkout', $message->payload['lawful_basis']['basis']);
        $this->assertArrayNotHasKey('email', $message->payload['customer']); // minimum data only
    }

    public function test_card1_e2e6_unpaid_pending_failed_and_unconfirmed_never_enter_contact(): void
    {
        foreach (['pending', 'failed', 'unknown_status'] as $i => $status) {
            $this->order('20'.$i, ['payment_status' => $status])->assertStatus(202);
        }

        foreach (WebshopOrder::all() as $order) {
            $this->assertSame('ineligible', $order->eligibility_status);
            $this->assertContains('not_paid', $order->eligibility_reasons);
            $this->assertSame('blocked', $order->contact_status);
        }
        $this->assertSame(0, ContactOpportunity::count());

        // Spoofed: a forged webhook never even becomes an order.
        $body = json_encode($this->genericOrder());
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->genericHeaders($body, 'forged-secret', 'x') as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $this->call('POST', $this->path, [], [], [], $server, $body)->assertStatus(401);
        $this->assertSame(3, WebshopOrder::count());
    }

    public function test_card1_e2e4_no_phone_is_imported_but_not_callable(): void
    {
        $this->order('3001', ['customer' => ['phone' => null]]);
        $this->order('3002', ['customer' => ['phone' => '12']]); // not a usable number

        foreach (['3001', '3002'] as $id) {
            $order = $this->orderRow($id);
            $this->assertSame('eligible', $order->eligibility_status);
            $this->assertSame('not_callable', $order->contact_status);
        }
        $this->assertSame(0, ContactOpportunity::count());
    }

    public function test_card3_f6_missing_lawful_basis_or_consent_blocks_with_reasons(): void
    {
        $this->order('4001', ['customer' => ['contact_consent' => false]]);
        $this->assertContains('no_contact_consent', $this->orderRow('4001')->eligibility_reasons);

        $this->order('4002', ['customer' => ['contact_consent' => null]]);
        $this->assertContains('no_contact_consent', $this->orderRow('4002')->eligibility_reasons);

        $this->putJson($this->url('/answer24/settings'), ['enabled' => false])->assertOk();
        $this->order('4003');
        $this->assertContains('answer24_disabled', $this->orderRow('4003')->eligibility_reasons);

        $this->assertSame(0, ContactOpportunity::count());
    }

    public function test_legitimate_interest_is_refused_unless_platform_allows_it(): void
    {
        $this->putJson($this->url('/answer24/settings'), ['enabled' => true, 'lawful_basis' => 'legitimate_interest', 'attestation' => true])
            ->assertStatus(422)->assertJsonPath('error.code', 'lawful_basis_not_permitted');
        $this->putJson($this->url('/answer24/settings'), ['enabled' => true, 'lawful_basis' => 'consent_at_checkout'])->assertStatus(422); // no attestation
    }

    public function test_card1_e2e7_orders_within_one_hour_are_grouped_into_one_contact(): void
    {
        $this->order('5001');
        $this->travel(20)->minutes();
        $this->order('5002', ['customer' => ['email' => 'ANNA@example.nl']]);
        $this->travel(30)->minutes();
        $this->order('5003', ['customer' => ['email' => 'piet@example.nl', 'first_name' => 'Piet']]); // another customer

        $this->assertSame(2, ContactOpportunity::count());

        $this->travel(15)->minutes();
        $this->artisan('shopbonus:close-contact-windows');
        $first = OutboxMessage::where('type', 'answer24.contact_requested')->get()->firstWhere(fn ($m) => $m->payload['customer']['first_name'] === 'Anna');
        $this->assertSame(['PS-5001', 'PS-5002'], array_column($first->payload['orders'], 'reference'));

        // After the window, a new order of the same customer is not contacted again (recently contacted).
        $this->order('5004');
        $this->assertSame('blocked', $this->orderRow('5004')->contact_status);
        $this->assertContains('recently_contacted', $this->orderRow('5004')->eligibility_reasons);
    }

    public function test_grouping_never_crosses_webshops(): void
    {
        $this->order('6001');
        $other = $this->activeWebshop(User::factory()->create());
        $this->assertSame(0, ContactOpportunity::where('webshop_id', $other->id)->count());
        $this->assertSame(1, ContactOpportunity::where('webshop_id', $this->shop->id)->count());
    }

    public function test_card1_e2e8_cancellation_before_dispatch_removes_and_cancels(): void
    {
        $this->order('7001', ['updated_at' => now()->toIso8601String()]);
        $this->order('7002', ['updated_at' => now()->toIso8601String()]);
        $this->assertSame(1, ContactOpportunity::count());

        $this->order('7001', ['payment_status' => 'cancelled', 'updated_at' => now()->addMinute()->toIso8601String()]);
        $this->assertSame('removed', $this->orderRow('7001')->contact_status);
        $this->assertSame('pending', ContactOpportunity::sole()->status);

        $this->order('7002', ['payment_status' => 'refunded', 'updated_at' => now()->addMinutes(2)->toIso8601String()]);
        $opportunity = ContactOpportunity::sole();
        $this->assertSame('cancelled', $opportunity->status);
        $this->assertSame('refunded', $opportunity->cancel_reason);

        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows');
        $this->assertSame(0, OutboxMessage::count()); // nothing ever queued
    }

    public function test_card1_e2e8_cancellation_after_queueing_cancels_pending_message_and_after_dispatch_queues_suppression(): void
    {
        $this->order('8001', ['updated_at' => now()->toIso8601String()]);
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows');
        $this->assertSame('ready', ContactOpportunity::sole()->status);

        // Refund while still queued: the queued request is cancelled before it is ever sent.
        $this->order('8001', ['payment_status' => 'refunded', 'updated_at' => now()->toIso8601String()]);
        $this->assertSame('cancelled', ContactOpportunity::sole()->status);
        $this->assertSame('cancelled', OutboxMessage::sole()->status);

        // Already dispatched: a cancellation (suppression) is queued for Answer24.
        $this->order('8002', ['customer' => ['email' => 'piet@example.nl'], 'updated_at' => now()->toIso8601String()]);
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows');
        $dispatched = ContactOpportunity::where('status', 'ready')->sole();
        $dispatched->forceFill(['status' => 'dispatched', 'dispatched_at' => now(), 'remote_reference' => 'A24-123'])->save();

        $this->order('8002', ['customer' => ['email' => 'piet@example.nl'], 'payment_status' => 'cancelled', 'updated_at' => now()->toIso8601String()]);
        $this->assertSame('cancellation_requested', $dispatched->fresh()->status);
        $cancel = OutboxMessage::where('type', 'answer24.contact_cancelled')->sole();
        $this->assertSame('A24-123', $cancel->payload['remote_reference']);
    }

    public function test_replayed_order_events_never_create_a_second_contact(): void
    {
        $this->order('9001', [], 'same-event')->assertStatus(202);
        $this->order('9001', [], 'same-event')->assertOk();
        $this->order('9001', ['status' => 'shipped']); // new event, same order

        $this->assertSame(1, ContactOpportunity::count());
        $this->assertSame(1, ContactOpportunity::sole()->links()->count());
    }

    public function test_suppressed_customers_are_never_contacted(): void
    {
        $this->postJson($this->url('/answer24/suppressions'), ['phone' => '+31 6 12345678', 'reason' => 'Asked not to be called'])->assertCreated();
        $this->postJson($this->url('/answer24/suppressions'), ['phone' => '0612345678'])->assertOk()->assertJsonPath('data.new_entries', 0);
        $this->assertSame(1, ContactSuppression::count());

        $this->order('9101');
        $this->assertContains('suppressed', $this->orderRow('9101')->eligibility_reasons);
        $this->assertSame(0, ContactOpportunity::count());
        $this->assertSame(2, AuditEvent::where('action', 'answer24.suppression_added')->count()); // both requests audited
    }

    public function test_below_minimum_order_value_is_not_eligible(): void
    {
        $this->order('9201', ['total_minor' => 2500]);
        $this->assertContains('below_minimum_order_value', $this->orderRow('9201')->eligibility_reasons);
    }

    public function test_settings_require_owner_and_queue_is_listed(): void
    {
        $manager = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $manager->id, 'role' => MembershipRole::Manager, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        $this->order('9301');

        Sanctum::actingAs($manager);
        $this->putJson($this->url('/answer24/settings'), ['enabled' => false])->assertForbidden();
        $this->getJson($this->url('/answer24/settings'))->assertOk()->assertJsonPath('data.lawful_basis', 'consent_at_checkout');
        $this->getJson($this->url('/answer24/opportunities'))->assertOk()
            ->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.orders', 1)->assertJsonPath('meta.dispatch_enabled', false);
    }
}
