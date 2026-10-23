<?php

namespace Tests\Feature\Answer24;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherStatus;
use App\Models\AuditEvent;
use App\Models\ContactOpportunity;
use App\Models\ContactOpportunityEvent;
use App\Models\IntegrationClient;
use App\Models\Offer;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\VoucherClaimReminder;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopOrder;
use App\Services\Offers\OfferService;
use App\Support\SignatureV1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

/**
 * B07b: Answer24 channel — signed dispatch and callbacks, handoff to a
 * reserved code, single-use claim, reminders and expiry (Trello #1 E2E 9,
 * #10 §6 reminder test, #23 "Answer24 free voucher").
 */
class Answer24ChannelTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase, SignsWebhooks;

    private const KEY = 'sbk_test_answer24';

    private const SECRET = 'answer24-test-secret-0123456789abcdef';

    private User $owner;

    private Webshop $shop;

    private Offer $offer;

    private string $webhookSecret;

    private string $webhookPath;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shopbonus.answer24.dispatch_enabled' => true,
            'shopbonus.answer24.base_url' => 'https://answer24.test',
            'shopbonus.answer24.signing_secret' => 'outbound-secret',
        ]);

        IntegrationClient::create(['name' => 'Answer24', 'target' => 'answer24', 'key_id' => self::KEY, 'secret' => self::SECRET,
            'scopes' => ['answer24.contact_results', 'answer24.handoffs', 'answer24.claims'], 'active' => true]);

        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        Sanctum::actingAs($this->owner);

        $membership = $this->shop->memberships()->sole()->setRelation('webshop', $this->shop)->setRelation('user', $this->owner);
        $offers = app(OfferService::class);
        $this->offer = $offers->transition($offers->create($membership, OfferAcquisition::MerchantReward, [
            'name' => '€10 korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 90,
        ]), OfferStatus::Active);
        foreach (['WOON-A24-0001', 'WOON-A24-0002'] as $code) {
            VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $this->offer->id, 'code' => $code,
                'code_hash' => VoucherCode::hashFor($this->shop->id, $code), 'code_last4' => substr($code, -4)]);
        }

        $this->putJson("/api/v1/workspaces/{$this->shop->public_id}/answer24/settings", ['enabled' => true, 'lawful_basis' => 'consent_at_checkout', 'attestation' => true])->assertOk();
        $r = $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $this->webhookSecret = $r->json('data.secrets.signing_secret');
        $this->webhookPath = (string) parse_url($r->json('data.setup.webhook_url'), PHP_URL_PATH);

        $this->app['auth']->forgetGuards();
    }

    // ------------------------------------------------------------------ helpers

    private function order(string $id, array $overrides = []): TestResponse
    {
        $body = json_encode($this->genericOrder(['order' => array_replace_recursive([
            'id' => $id, 'reference' => "PS-{$id}", 'total_minor' => 7500, 'updated_at' => now()->toIso8601String(),
            'customer' => ['first_name' => 'Anna', 'email' => 'anna@example.nl', 'phone' => '06 1234 5678', 'contact_consent' => true],
        ], $overrides)]));

        return $this->call('POST', $this->webhookPath, [], [], [], $this->server($this->genericHeaders($body, $this->webhookSecret, 'e-'.$id.'-'.uniqid())), $body);
    }

    /** @param array<string, string> $headers */
    private function server(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $server;
    }

    /** Signed Answer24 → Shopbonus request. */
    private function signed(string $method, string $uri, ?array $data = null, ?string $eventId = null, array $override = []): TestResponse
    {
        $body = $data === null ? '' : (string) json_encode($data);
        $headers = [
            'X-Shopbonus-Key' => self::KEY,
            'X-Shopbonus-Signature' => SignatureV1::header(self::SECRET, $body),
            'X-Shopbonus-Event-Id' => $eventId ?? 'a24-'.uniqid('', true),
            ...$override,
        ];

        return $this->call($method, '/api/v1/integrations/answer24'.$uri, [], [], [], $this->server(array_filter($headers, fn ($v) => $v !== null)), $body);
    }

    /** Order → grouped → window closed → dispatched to Answer24 (faked). */
    private function dispatchedOpportunity(string $orderId = '1001', ?Webshop $shop = null): ContactOpportunity
    {
        Http::fake(['answer24.test/*' => Http::response(['reference' => 'A24-'.$orderId], 202)]);
        $this->order($orderId)->assertStatus(202);
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows')->assertSuccessful();
        $this->artisan('shopbonus:dispatch-outbox')->assertSuccessful();

        return ContactOpportunity::where('remote_reference', 'A24-'.$orderId)->sole();
    }

    private function contactResult(ContactOpportunity $opp, string $status, ?string $at = null, ?string $eventId = null): TestResponse
    {
        return $this->signed('POST', '/contact-results', [
            'webshop_id' => $this->shop->public_id, 'opportunity_id' => $opp->public_id, 'status' => $status,
            'occurred_at' => $at ?? now()->toIso8601String(), 'remote_reference' => $opp->remote_reference,
        ], $eventId);
    }

    private function handoff(ContactOpportunity $opp): TestResponse
    {
        return $this->signed('POST', '/handoffs', ['webshop_id' => $this->shop->public_id, 'opportunity_id' => $opp->public_id]);
    }

    private function tokenFrom(TestResponse $handoff): string
    {
        return basename((string) parse_url($handoff->json('data.claim_url'), PHP_URL_PATH));
    }

    private function consumer(string $email = 'anna@example.nl'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    // ------------------------------------------------------------------ tests

    public function test_card1_e2e9_signed_request_callback_and_single_use_activation(): void
    {
        $opp = $this->dispatchedOpportunity();

        // Outbound request was signed with the Shopbonus scheme and carried minimum data.
        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === 'https://answer24.test/api/shopbonus/v1/contact-requests'
                && SignatureV1::verify('outbound-secret', $request->body(), $request->header('X-Shopbonus-Signature')[0])
                && $request['customer']['phone'] === '+31612345678'
                && ! isset($request['customer']['email'])
                && $request->header('Idempotency-Key')[0] !== '';
        });
        $this->assertSame('dispatched', $opp->status);
        $this->assertSame('dispatched', WebshopOrder::sole()->contact_status);

        $this->contactResult($opp, 'accepted')->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.outcome', 'applied');
        $this->assertSame('accepted', WebshopOrder::sole()->contact_status);

        $first = $this->handoff($opp)->assertCreated()->assertJsonPath('data.status', 'open');
        $again = $this->handoff($opp)->assertOk();
        $this->assertSame($first->json('data.claim_url'), $again->json('data.claim_url'));
        $this->assertSame(1, Voucher::count());
        $voucher = Voucher::sole();
        $this->assertSame(VoucherStatus::Reserved, $voucher->status);
        $this->assertSame('answer24_claim', $voucher->origin->value);
        $this->assertEqualsWithDelta(90, now()->diffInDays($voucher->claim_expires_at), 1);
        $token = $this->tokenFrom($first);
        $this->assertSame(24, strlen($token));
        $this->assertStringNotContainsString($token, (string) VoucherClaim::sole()->getRawOriginal('token'));

        // Public preview shows the offer, not inventory internals.
        $preview = $this->postJson('/api/v1/claims/preview', ['token' => $token])->assertOk()
            ->assertJsonPath('data.webshop.name', 'Woon & Co')->assertJsonPath('data.offer.summary', '€10.00 from €50.00');
        $this->assertArrayNotHasKey('code_mode', $preview->json('data.offer'));
        $this->assertArrayNotHasKey('code', $preview->json('data'));

        // Claim needs a verified account.
        $this->postJson('/api/v1/claims/accept', ['token' => $token])->assertUnauthorized();
        $anna = $this->consumer();
        Sanctum::actingAs($anna);
        $claimed = $this->postJson('/api/v1/claims/accept', ['token' => $token])->assertOk()
            ->assertJsonPath('data.status', 'issued')->assertJsonPath('data.webshop.name', 'Woon & Co');
        $this->assertContains($claimed->json('data.code'), ['WOON-A24-0001', 'WOON-A24-0002']);
        $this->postJson('/api/v1/claims/accept', ['token' => $token])->assertOk()->assertJsonPath('data.voucher_id', $claimed->json('data.voucher_id'));

        // Single use: nobody else can take it, and the link is spent.
        Sanctum::actingAs($this->consumer('anna.other@example.nl'));
        $this->postJson('/api/v1/claims/accept', ['token' => $token])->assertStatus(410)->assertJsonPath('error.code', 'claim_already_used');
        $this->postJson('/api/v1/claims/preview', ['token' => $token])->assertStatus(410);

        $this->assertSame(1, Voucher::where('consumer_user_id', $anna->id)->where('status', VoucherStatus::Issued)->count());
        $this->assertSame(1, VoucherCode::where('status', VoucherCodeStatus::Issued)->count());
        $this->assertSame(1, OutboxMessage::where('type', 'answer24.claim_completed')->count());
        $this->assertSame('claimed', $this->signed('GET', '/claims/'.VoucherClaim::sole()->public_id)->assertOk()->json('data.status'));
    }

    public function test_claim_requires_the_order_email_and_is_audited(): void
    {
        $opp = $this->dispatchedOpportunity();
        $this->contactResult($opp, 'accepted')->assertOk();
        $token = $this->tokenFrom($this->handoff($opp)->assertCreated());

        Sanctum::actingAs($this->consumer('someone.else@example.nl'));
        $this->postJson('/api/v1/claims/accept', ['token' => $token])->assertForbidden()->assertJsonPath('error.code', 'claim_email_mismatch');

        $this->assertSame(VoucherStatus::Reserved, Voucher::sole()->status);
        $this->assertSame('open', VoucherClaim::sole()->status);
        $this->assertSame(1, AuditEvent::where('action', 'answer24.claim_email_mismatch')->count());

        Sanctum::actingAs(User::factory()->unverified()->create(['email' => 'anna@example.nl']));
        $this->postJson('/api/v1/claims/accept', ['token' => $token])->assertForbidden()->assertJsonPath('error.code', 'email_not_verified');

        $this->postJson('/api/v1/claims/preview', ['token' => str_repeat('x', 24)])->assertNotFound()->assertJsonPath('error.code', 'claim_not_found');
    }

    public function test_signature_scope_and_replay_are_enforced(): void
    {
        $opp = $this->dispatchedOpportunity();
        $data = ['webshop_id' => $this->shop->public_id, 'opportunity_id' => $opp->public_id, 'status' => 'accepted', 'occurred_at' => now()->toIso8601String()];

        $this->signed('POST', '/contact-results', $data, override: ['X-Shopbonus-Key' => null])->assertUnauthorized()->assertJsonPath('error.code', 'integration_signature_invalid');
        $this->signed('POST', '/contact-results', $data, override: ['X-Shopbonus-Signature' => SignatureV1::header('wrong-secret', (string) json_encode($data))])->assertUnauthorized();
        $this->signed('POST', '/contact-results', $data, override: ['X-Shopbonus-Signature' => SignatureV1::header(self::SECRET, (string) json_encode($data), time() - 301)])->assertUnauthorized();
        $this->signed('POST', '/contact-results', $data, override: ['X-Shopbonus-Event-Id' => null])->assertStatus(400)->assertJsonPath('error.code', 'event_id_required');
        $this->assertSame('dispatched', $opp->fresh()->status);
        $this->assertGreaterThanOrEqual(3, AuditEvent::where('http_status', 401)->where('route', 'like', '%contact-results%')->count());

        // Identical retry: processed once, answered the same way.
        $this->signed('POST', '/contact-results', $data, 'evt-1')->assertOk()->assertJsonPath('data.outcome', 'applied');
        $this->signed('POST', '/contact-results', $data, 'evt-1')->assertOk()->assertJsonPath('data.outcome', 'duplicate');
        $this->assertSame(1, ContactOpportunityEvent::count());
        // Same event ID, different instruction.
        $this->signed('POST', '/contact-results', ['status' => 'completed'] + $data, 'evt-1')->assertStatus(409)->assertJsonPath('error.code', 'event_id_reused');

        IntegrationClient::create(['name' => 'Limited', 'target' => 'answer24', 'key_id' => 'sbk_limited', 'secret' => 'limited-secret',
            'scopes' => ['answer24.claims'], 'active' => true]);
        $body = (string) json_encode($data);
        $this->call('POST', '/api/v1/integrations/answer24/contact-results', [], [], [], $this->server([
            'X-Shopbonus-Key' => 'sbk_limited', 'X-Shopbonus-Signature' => SignatureV1::header('limited-secret', $body), 'X-Shopbonus-Event-Id' => 'x',
        ]), $body)->assertForbidden()->assertJsonPath('error.code', 'integration_scope_denied');

        $this->artisan('shopbonus:create-integration-client', ['name' => 'x', '--revoke' => self::KEY])->assertSuccessful();
        $this->signed('POST', '/contact-results', $data)->assertUnauthorized();
    }

    public function test_callbacks_cannot_cross_tenants_and_follow_the_state_machine(): void
    {
        $opp = $this->dispatchedOpportunity();
        $other = $this->activeWebshop(User::factory()->create());

        $this->signed('POST', '/contact-results', ['webshop_id' => $other->public_id, 'opportunity_id' => $opp->public_id, 'status' => 'accepted', 'occurred_at' => now()->toIso8601String()])
            ->assertNotFound()->assertJsonPath('error.code', 'opportunity_not_found');
        $this->signed('POST', '/handoffs', ['webshop_id' => $other->public_id, 'opportunity_id' => $opp->public_id])->assertNotFound();

        // No handoff before permitted engagement.
        $this->handoff($opp)->assertStatus(409)->assertJsonPath('error.code', 'handoff_not_permitted');

        $t0 = now();
        $this->contactResult($opp, 'attempted', $t0->toIso8601String())->assertOk();
        $this->contactResult($opp, 'completed', $t0->addMinutes(5)->toIso8601String())->assertOk()->assertJsonPath('data.status', 'completed');
        // Late, older callback: recorded, not applied.
        $this->contactResult($opp, 'attempted', $t0->addMinutes(2)->toIso8601String())->assertOk()->assertJsonPath('data.outcome', 'stale')->assertJsonPath('data.status', 'completed');
        // Newer but impossible transition.
        $this->contactResult($opp, 'accepted', $t0->addMinutes(10)->toIso8601String())->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
        $this->signed('POST', '/contact-results', ['webshop_id' => $this->shop->public_id, 'opportunity_id' => $opp->public_id, 'status' => 'failed',
            'occurred_at' => $t0->addMinutes(11)->toIso8601String(), 'remote_reference' => 'SOMETHING-ELSE'])->assertStatus(409)->assertJsonPath('error.code', 'remote_reference_mismatch');

        $this->assertSame(['applied', 'applied', 'stale'], ContactOpportunityEvent::orderBy('id')->pluck('outcome')->all());
        $this->assertSame('completed', WebshopOrder::sole()->contact_status);
        $this->handoff($opp)->assertCreated();
    }

    public function test_results_for_a_request_never_sent_are_refused(): void
    {
        config(['shopbonus.answer24.dispatch_enabled' => false]);
        $this->order('1001')->assertStatus(202);
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows')->assertSuccessful();
        $opp = ContactOpportunity::sole();
        $this->assertSame('ready', $opp->status);

        $this->contactResult($opp, 'accepted')->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    }

    public function test_card10_reminders_on_days_30_60_80_without_duplicates_or_after_claim(): void
    {
        $opp = $this->dispatchedOpportunity();
        $this->contactResult($opp, 'accepted')->assertOk();
        $handoff = $this->handoff($opp)->assertCreated();
        $claimId = $handoff->json('data.claim_id');
        $remind = fn (int $day) => $this->signed('POST', "/claims/{$claimId}/reminders", ['day' => $day])->assertOk();

        $this->assertSame([false, 'not_due'], [$remind(30)->json('data.send'), $remind(30)->json('data.reason')]);
        $this->signed('POST', "/claims/{$claimId}/reminders", ['day' => 45])->assertUnprocessable();

        $this->travel(30)->days();
        $this->assertTrue($remind(30)->json('data.send'));
        // Repeated queue delivery: never a second reminder.
        $this->assertSame([false, 'already_sent'], [$remind(30)->json('data.send'), $remind(30)->json('data.reason')]);
        $this->assertSame('not_due', $remind(60)->json('data.reason'));

        $this->travel(31)->days(); // day 61
        $this->assertSame('superseded', $remind(30)->json('data.reason'));
        $this->assertTrue($remind(60)->json('data.send'));

        // Claimed before day 80: no further reminder.
        Sanctum::actingAs($this->consumer());
        $this->postJson('/api/v1/claims/accept', ['token' => $this->tokenFrom($handoff)])->assertOk();
        $this->travel(19)->days(); // day 80
        $this->assertSame([false, 'claimed'], [$remind(80)->json('data.send'), $remind(80)->json('data.reason')]);
        $this->assertSame(2, VoucherClaim::sole()->hasMany(VoucherClaimReminder::class, 'claim_id')->count());
    }

    public function test_suppressed_customer_gets_no_reminder(): void
    {
        $opp = $this->dispatchedOpportunity();
        $this->contactResult($opp, 'accepted')->assertOk();
        $claimId = $this->handoff($opp)->assertCreated()->json('data.claim_id');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/workspaces/{$this->shop->public_id}/answer24/suppressions", ['email' => 'anna@example.nl', 'reason' => 'Opt-out'])->assertCreated();
        $this->travel(30)->days();

        $this->signed('POST', "/claims/{$claimId}/reminders", ['day' => 30])->assertOk()
            ->assertJsonPath('data.send', false)->assertJsonPath('data.reason', 'suppressed');
    }

    public function test_unclaimed_entitlement_expires_after_90_days_and_releases_the_code(): void
    {
        $opp = $this->dispatchedOpportunity();
        $this->contactResult($opp, 'accepted')->assertOk();
        $handoff = $this->handoff($opp)->assertCreated();
        $this->assertSame(1, VoucherCode::where('status', VoucherCodeStatus::Reserved)->count());

        $this->travel(91)->days();
        $this->artisan('shopbonus:expire-vouchers')->assertSuccessful();

        $this->assertSame(VoucherStatus::Cancelled, Voucher::sole()->status);
        $this->assertSame(2, VoucherCode::where('status', VoucherCodeStatus::Available)->count());
        $this->assertSame('expired', VoucherClaim::sole()->status);
        $this->assertSame(1, OutboxMessage::where('type', 'answer24.claim_expired')->count());
        $this->postJson('/api/v1/claims/preview', ['token' => $this->tokenFrom($handoff)])->assertStatus(410)->assertJsonPath('error.code', 'claim_expired');
        $this->signed('POST', '/claims/'.$handoff->json('data.claim_id').'/reminders', ['day' => 80])->assertJsonPath('data.reason', 'expired');

        // Running it again changes nothing.
        $this->artisan('shopbonus:expire-vouchers')->assertSuccessful();
        $this->assertSame(1, OutboxMessage::where('type', 'answer24.claim_expired')->count());
    }

    public function test_dispatcher_is_a_no_op_while_disabled(): void
    {
        config(['shopbonus.answer24.dispatch_enabled' => false]);
        Http::fake();
        $this->order('1001')->assertStatus(202);
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows')->assertSuccessful();
        $this->artisan('shopbonus:dispatch-outbox')->expectsOutputToContain('disabled')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('pending', OutboxMessage::sole()->status);
        $this->assertSame('ready', ContactOpportunity::sole()->status);
    }

    public function test_dispatcher_retries_with_backoff_and_fails_permanent_errors(): void
    {
        Http::fakeSequence('answer24.test/*')->push(['error' => 'busy'], 503)->push(['error' => 'bad'], 422);
        $this->order('1001')->assertStatus(202);
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows')->assertSuccessful();

        $this->artisan('shopbonus:dispatch-outbox')->assertSuccessful();
        $message = OutboxMessage::sole();
        $this->assertSame(['pending', 1, 'http_503'], [$message->status, $message->attempts, $message->last_error]);
        $this->assertTrue($message->next_attempt_at->isFuture());

        $this->artisan('shopbonus:dispatch-outbox')->assertSuccessful(); // not due yet
        Http::assertSentCount(1);

        $this->travel(3)->minutes();
        $this->artisan('shopbonus:dispatch-outbox')->assertSuccessful();
        $this->assertSame(['failed', 2, 'http_422'], [$message->fresh()->status, $message->fresh()->attempts, $message->fresh()->last_error]);
        $this->assertSame('ready', ContactOpportunity::sole()->status);
    }

    public function test_cancellation_during_dispatch_queues_a_stop_for_answer24(): void
    {
        $this->order('1001')->assertStatus(202);
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows')->assertSuccessful();

        Http::fake(function () {
            // The order is cancelled while the request is in flight.
            $this->order('1001', ['payment_status' => 'refunded', 'status' => 'refunded', 'updated_at' => now()->addSecond()->toIso8601String()])->assertStatus(202);

            return Http::response(['reference' => 'A24-RACE'], 202);
        });
        $this->artisan('shopbonus:dispatch-outbox')->assertSuccessful();

        $opp = ContactOpportunity::sole();
        $this->assertSame('cancellation_requested', $opp->status);
        $this->assertSame('A24-RACE', $opp->remote_reference);
        $stop = OutboxMessage::where('type', 'answer24.contact_cancelled')->sole();
        $this->assertSame('A24-RACE', $stop->payload['remote_reference']);
    }

    public function test_out_of_stock_handoff_is_refused_without_a_claim(): void
    {
        VoucherCode::query()->update(['status' => VoucherCodeStatus::Invalid->value]);
        $opp = $this->dispatchedOpportunity();
        $this->contactResult($opp, 'accepted')->assertOk();

        $this->handoff($opp)->assertStatus(409)->assertJsonPath('error.code', 'offer_out_of_stock');
        $this->assertSame(0, VoucherClaim::count());
    }

    public function test_create_integration_client_shows_the_secret_once_and_stores_it_encrypted(): void
    {
        $this->artisan('shopbonus:create-integration-client', ['name' => 'Answer24 prod'])
            ->expectsOutputToContain('Key ID: sbk_')->expectsOutputToContain('Secret: ')->assertSuccessful();

        $client = IntegrationClient::where('name', 'Answer24 prod')->sole();
        $this->assertSame(48, strlen($client->secret));
        $this->assertStringNotContainsString($client->secret, (string) $client->getRawOriginal('secret'));
        $this->assertSame(['answer24.contact_results', 'answer24.handoffs', 'answer24.claims'], $client->scopes);
        $this->artisan('shopbonus:create-integration-client', ['name' => 'x', '--scope' => ['admin']])->assertFailed();
    }
}
