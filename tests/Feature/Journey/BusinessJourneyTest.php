<?php

namespace Tests\Feature\Journey;

use App\Enums\AgreementTemplateStatus;
use App\Enums\PlatformRole;
use App\Enums\VoucherStatus;
use App\Models\AffiliateConnection;
use App\Models\AffiliateRewardRule;
use App\Models\AgreementTemplate;
use App\Models\AuditEvent;
use App\Models\ContactOpportunity;
use App\Models\FundingTopUp;
use App\Models\IntegrationClient;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopOrder;
use App\Services\Affiliate\AffiliateSync;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\WebsiteFileFetcher;
use App\Services\Wallet\WalletService;
use App\Support\SignatureV1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\SignsWebhooks;
use Tests\Concerns\TracksWithMarketingConsent;
use Tests\Fakes\FakeHostResolver;
use Tests\Fakes\FakeWebsiteFileFetcher;
use Tests\TestCase;

/**
 * Trello #22 "Full business journey", steps 1–17, as one continuous flow
 * through the real HTTP API, services, queue commands and database. Only
 * the outside world is synthetic: DNS, mail, the Answer24 endpoint and the
 * affiliate network report. No step marks success without the API.
 */
class BusinessJourneyTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase, SignsWebhooks;
    use TracksWithMarketingConsent;

    private const A24_KEY = 'a24-journey-key';

    private const A24_SECRET = 'SYNTHETIC_answer24_inbound_secret';

    private FakeHostResolver $dns;

    private string $hookPath;

    private string $hookSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        Notification::fake();
        Storage::fake('local');
        $this->dns = new FakeHostResolver;
        $this->app->instance(HostResolver::class, $this->dns);
        $this->app->instance(WebsiteFileFetcher::class, new FakeWebsiteFileFetcher);
        config(['shopbonus.answer24.dispatch_enabled' => true, 'shopbonus.answer24.base_url' => 'https://answer24.test', 'shopbonus.answer24.signing_secret' => 'SYNTHETIC_outbound']);
        IntegrationClient::create(['name' => 'Answer24', 'target' => 'answer24', 'key_id' => self::A24_KEY, 'secret' => self::A24_SECRET,
            'scopes' => ['answer24.contact_results', 'answer24.handoffs', 'answer24.claims'], 'active' => true]);
        config(['shopbonus.payments.mollie.key' => 'test_SYNTHETIC_journey_key']);
        Http::fake(['answer24.test/*' => Http::response(['reference' => 'A24-JOURNEY-1'], 202), 'api.mollie.com/v2/*' => function (HttpRequest $r) {
            if ($r->method() === 'POST') {
                return Http::response(['id' => 'tr_journey01', 'status' => 'open', 'amount' => $r['amount'], 'metadata' => $r['metadata'],
                    '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/journey01']]], 201);
            }
            $t = FundingTopUp::where('provider_ref', 'tr_journey01')->firstOrFail();

            return Http::response(['id' => 'tr_journey01', 'status' => $this->mollieStatus, 'amount' => ['currency' => 'EUR', 'value' => sprintf('%d.%02d', intdiv($t->amount_minor, 100), $t->amount_minor % 100)],
                'metadata' => ['top_up_id' => $t->public_id]]);
        }]);
    }

    private string $mollieStatus = 'open';

    private function mollieWebhook(): void
    {
        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/payments/mollie/webhook', ['id' => 'tr_journey01'], ['Accept' => 'application/json'])->assertOk();
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
        // Browser-style (stateful) requests authenticate through the session guard.
        $this->actingAs($user, 'web');
    }

    private function ws(Webshop $shop, string $suffix = ''): string
    {
        return '/api/v1/workspaces/'.$shop->public_id.$suffix;
    }

    /** @param array<string, mixed> $order */
    private function orderEvent(array $order, string $eventId): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $body = (string) json_encode($this->genericOrder(['order' => $order]));
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->genericHeaders($body, $this->hookSecret, $eventId) as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $this->call('POST', $this->hookPath, [], [], [], $server, $body);
    }

    /** @param array<string, mixed>|null $data */
    private function answer24(string $method, string $uri, ?array $data = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $body = $data === null ? '' : (string) json_encode($data);

        return $this->call($method, '/api/v1/integrations/answer24'.$uri, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHOPBONUS_KEY' => self::A24_KEY, 'HTTP_X_SHOPBONUS_SIGNATURE' => SignatureV1::header(self::A24_SECRET, $body), 'HTTP_X_SHOPBONUS_EVENT_ID' => 'a24-'.uniqid('', true)], $body);
    }

    private function registerAndVerify(string $type, string $email, string $name): User
    {
        // A different person in a fresh browser.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->register(['account_type' => $type, 'email' => $email, 'first_name' => $name])->assertCreated();
        $user = User::where('email', $email)->sole();
        $this->postJson('/api/v1/auth/email/verify-code', ['code' => $this->lastCodeFor($user)])->assertOk();

        return $user->fresh();
    }

    public function test_full_business_journey_through_the_real_api(): void
    {
        $admin = User::factory()->create(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()]);
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $template = AgreementTemplate::create(['key' => AgreementTemplate::MERCHANT, 'version' => '2026.1', 'locale' => 'nl', 'title' => 'Merchantovereenkomst',
            'body' => 'Tussen Shopbonus en {{legal_name}} (KvK {{kvk_number}}).', 'status' => AgreementTemplateStatus::Published, 'published_at' => now(), 'created_by' => $admin->id]);

        // 1. Merchant registers and verifies email.
        $owner = $this->registerAndVerify('merchant', 'jan@fietsen-utrecht.nl', 'Jan');
        $this->assertNotNull($owner->email_verified_at);

        // 2. Workspace, business identity and DNS ownership proof.
        $this->as($owner);
        $shop = Webshop::where('public_id', $this->postJson('/api/v1/workspaces', ['name' => 'Fietsen Utrecht', 'platform' => 'prestashop'])->assertCreated()->json('data.id'))->sole();
        $this->putJson($this->ws($shop, '/business-profile'), ['legal_name' => 'Fietsen Utrecht B.V.', 'trading_name' => 'Fietsen Utrecht', 'website_url' => 'https://fietsen-utrecht.nl',
            'kvk_number' => '12345678', 'contact_email' => 'info@fietsen-utrecht.nl', 'main_contact_name' => 'Jan de Vries', 'address_line' => 'Oudegracht 1',
            'postal_code' => '3511 AA', 'city' => 'Utrecht', 'country_code' => 'NL', 'confirm' => true])->assertOk();
        $challenge = $this->postJson($this->ws($shop, '/ownership/verifications'), ['method' => 'dns_txt'])->assertCreated();
        $this->dns->txt['_shopbonus-verification.fietsen-utrecht.nl'] = [$challenge->json('data.instructions.record_value')];
        $this->postJson($this->ws($shop, '/ownership/verifications/'.$challenge->json('data.id').'/check'))->assertOk()->assertJsonPath('data.status', 'verified');

        // 3–4. Current agreement accepted; this verifiable owner is approved automatically (Super Admin review otherwise).
        $agreement = $this->getJson($this->ws($shop, '/agreement'))->assertOk()->json('data');
        $this->assertSame($template->version, $agreement['version']);
        $this->postJson($this->ws($shop, '/agreement/accept'), ['accept' => true, 'confirm_identity' => true, 'signatory_name' => 'Jan de Vries',
            'version' => $agreement['version'], 'document_hash' => $agreement['document_hash']])->assertCreated()->assertJsonPath('data.webshop_status', 'approved');

        // 5. The owner reaches exactly this workspace; another user does not.
        $this->getJson('/api/v1/workspaces')->assertOk()->assertJsonPath('data.0.id', $shop->public_id);
        $stranger = User::factory()->create();
        $this->as($stranger);
        $this->getJson($this->ws($shop, '/orders'))->assertNotFound();

        // 6. Platform connection and lawful-basis settings.
        $this->as($owner);
        $connection = $this->postJson($this->ws($shop, '/connections'), ['platform' => 'prestashop'])->assertCreated();
        $this->hookSecret = $connection->json('data.secrets.signing_secret');
        $this->hookPath = (string) parse_url($connection->json('data.setup.webhook_url'), PHP_URL_PATH);
        $this->putJson($this->ws($shop, '/answer24/settings'), ['enabled' => true, 'lawful_basis' => 'consent_at_checkout', 'attestation' => true])->assertOk();

        // 8a. Reward funding: credited only after the server re-reads the payment from Mollie, never from a redirect.
        $this->as($owner);
        $this->postJson($this->ws($shop, '/funding/top-ups'), ['amount_minor' => 50000])->assertCreated()->assertJsonPath('data.state', 'pending');
        $this->mollieWebhook();
        $this->as($owner);
        $this->getJson($this->ws($shop, '/funding'))->assertJsonPath('data.available_minor', 0);
        $this->mollieStatus = 'paid';
        $this->mollieWebhook();
        $this->mollieWebhook(); // Mollie retries
        $this->as($owner);
        $this->getJson($this->ws($shop, '/funding'))->assertJsonPath('data.available_minor', 50000)->assertJsonPath('data.withdrawable', false);

        // 8. Two offers: an Answer24 reward and a points-purchasable voucher, each with real code inventory.
        $offers = [];
        foreach (['reward' => ['acquisition' => 'merchant_reward', 'name' => '€10 korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 90],
            'wallet' => ['acquisition' => 'wallet_purchase', 'name' => '€5 korting', 'discount_type' => 'fixed', 'discount_value' => 500, 'price_minor' => 200, 'redemption_validity_days' => 90]] as $key => $offer) {
            $id = $this->postJson($this->ws($shop, '/offers'), $offer)->assertCreated()->json('data.id');
            $codes = $key === 'reward' ? "FIETS-A24-0001\nFIETS-A24-0002" : "FIETS-PNT-0001\nFIETS-PNT-0002";
            $import = $this->postJson($this->ws($shop, "/offers/{$id}/code-imports"), ['codes' => $codes])->assertCreated()->json('data.id');
            $this->postJson($this->ws($shop, "/code-imports/{$import}/commit"))->assertOk();
            $this->postJson($this->ws($shop, "/offers/{$id}/activate"))->assertOk()->assertJsonPath('data.status', 'active');
            $offers[$key] = $id;
        }
        $publicSlug = $this->putJson($this->ws($shop, '/public-profile'), ['short_description' => 'Fietsen', 'website_url' => 'https://fietsen-utrecht.nl', 'categories' => ['sports_outdoor'], 'published' => true])
            ->assertOk()->json('data.slug');
        $this->assertIsString($publicSlug);

        // 7 + 9. A verified paid order appears once in the Order Sheet; a pending order is imported but never contactable.
        $paid = ['id' => 'PS-1001', 'reference' => 'XKB-1001', 'total_minor' => 7995, 'updated_at' => now()->toIso8601String(),
            'customer' => ['first_name' => 'Anna', 'email' => 'anna@example.nl', 'phone' => '06 1234 5678', 'contact_consent' => true]];
        $this->orderEvent($paid, 'evt-1001')->assertAccepted();
        $this->orderEvent($paid, 'evt-1001')->assertSuccessful(); // provider redelivery
        $this->orderEvent(['id' => 'PS-1002', 'reference' => 'XKB-1002', 'payment_status' => 'pending', 'status' => 'awaiting_payment', 'paid_at' => null,
            'customer' => ['first_name' => 'Piet', 'email' => 'piet@example.nl', 'phone' => '06 8765 4321', 'contact_consent' => true]], 'evt-1002')->assertAccepted();
        $this->as($owner);
        $sheet = $this->getJson($this->ws($shop, '/orders'))->assertOk();
        $this->assertSame(2, $sheet->json('meta.total'));
        $this->assertSame(1, WebshopOrder::where('external_order_id', 'PS-1001')->count());

        // 10. After the one-hour grouping window only the paid order is sent to Answer24, once, with minimum data.
        $this->travel(61)->minutes();
        $this->artisan('shopbonus:close-contact-windows')->assertSuccessful();
        $this->artisan('shopbonus:dispatch-outbox')->assertSuccessful();
        $this->artisan('shopbonus:dispatch-outbox')->assertSuccessful();
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://answer24.test/')));
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://answer24.test/') && $r['customer']['phone'] === '+31612345678' && ! isset($r['customer']['email']));
        $opportunity = ContactOpportunity::sole();
        $this->assertSame('A24-JOURNEY-1', $opportunity->remote_reference);

        // 11. Answer24 reports acceptance and hands off; Anna registers independently and claims with the order email.
        $this->answer24('POST', '/contact-results', ['webshop_id' => $shop->public_id, 'opportunity_id' => $opportunity->public_id, 'status' => 'accepted',
            'occurred_at' => now()->toIso8601String(), 'remote_reference' => 'A24-JOURNEY-1'])->assertOk();
        $token = basename((string) parse_url($this->answer24('POST', '/handoffs', ['webshop_id' => $shop->public_id, 'opportunity_id' => $opportunity->public_id])
            ->assertCreated()->json('data.claim_url'), PHP_URL_PATH));
        $anna = $this->registerAndVerify('consumer', 'anna@example.nl', 'Anna');
        $this->as($anna);
        $claimed = $this->postJson('/api/v1/claims/accept', ['token' => $token])->assertOk()->assertJsonPath('data.status', 'issued');
        $rewardCode = $claimed->json('data.code');
        $this->assertContains($rewardCode, ['FIETS-A24-0001', 'FIETS-A24-0002']);

        // 12–13. Anna follows the affiliate link; pending commission is not spendable, approval credits once.
        $network = AffiliateConnection::create(['network' => 'daisycon', 'name' => 'Daisycon', 'status' => 'connected', 'credentials' => ['publisher_id' => '1', 'access_token' => 'SYNTHETIC', 'media_id' => '9'],
            'postback_token_hash' => AffiliateConnection::hashToken('daisycon-token')]);
        AffiliateRewardRule::create(['version' => 1, 'unit' => 'eur_credit', 'share_bps' => 5000, 'min_commission_minor' => 50, 'review_above_minor' => 5000, 'active' => true, 'created_by' => $admin->id]);
        $sync = app(AffiliateSync::class);
        $sync->import($network, [['id' => 'P1', 'name' => 'Fietsen Utrecht', 'url' => 'https://fietsen-utrecht.nl', 'status' => 'active', 'currency_code' => 'EUR',
            'commission' => ['type' => 'percentage', 'value' => '6', 'restricted' => false], 'cashback_allowed' => true, 'tracking_url' => 'https://lt45.net/c/?si=1&li=2&wi=3']], [], $admin->id);
        $this->as($anna);
        $this->get('/api/v1/public/webshops/'.$publicSlug.'/visit'.$this->marketingConsentQuery())->assertRedirect();
        $click = DB::table('affiliate_clicks')->where('user_id', $anna->id)->value('reference');
        $tx = fn (string $status, string $at) => ['affiliatemarketing_id' => 'D-1', 'program_id' => 'P1', 'date' => now()->toIso8601String(), 'last_modified' => $at,
            'parts' => [['subid' => $click, 'referencenumber' => 'ORD-AFF-1', 'revenue' => 79.95, 'commission' => 4.797, 'status' => $status, 'currency_code' => 'EUR']]];
        $sync->import($network, [], [$tx('pending', '2026-10-23T12:00:00Z')], $admin->id);
        $this->as($anna);
        $this->getJson('/api/v1/consumer/wallet')->assertOk()->assertJsonPath('data.balances.eur_credit.available', 0)->assertJsonPath('data.balances.eur_credit.pending', 239)->assertJsonPath('data.withdrawable', false);
        $sync->import($network, [], [$tx('approved', '2026-10-24T09:00:00Z')], $admin->id);
        $sync->import($network, [], [$tx('approved', '2026-10-24T09:00:00Z')], $admin->id); // network retry
        $this->as($anna);
        $this->getJson('/api/v1/consumer/wallet')->assertOk()->assertJsonPath('data.balances.eur_credit.available', 239)->assertJsonPath('data.balances.eur_credit.pending', 0);

        // 14–15. Anna buys the points voucher; debit and code issuance are one idempotent step, shown in My Vouchers.
        // Discovery: the webshop and its offers are public; only the points voucher has a price.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/webshops/'.$publicSlug)->assertOk();
        $listed = collect($this->getJson('/api/v1/public/offers?webshop='.$publicSlug)->assertOk()->json('data'));
        $this->assertCount(2, $listed);
        $slug = $listed->firstWhere('price_minor', 200)['slug'];
        $this->as($anna);
        $purchase = $this->withHeaders(['Idempotency-Key' => 'journey-buy-1'])->postJson('/api/v1/consumer/checkouts', ['offer' => $slug])->assertCreated()->assertJsonPath('data.state', 'completed');
        $this->withHeaders(['Idempotency-Key' => 'journey-buy-1'])->postJson('/api/v1/consumer/checkouts', ['offer' => $slug])->assertOk()->assertJsonPath('data.id', $purchase->json('data.id'));
        $this->getJson('/api/v1/consumer/wallet')->assertJsonPath('data.balances.eur_credit.available', 39);
        $mine = collect($this->getJson('/api/v1/consumer/vouchers')->assertOk()->json('data'));
        $this->assertCount(2, $mine);
        $bought = $this->getJson('/api/v1/consumer/vouchers/'.$purchase->json('data.voucher_id'))->assertOk()->json('data');
        $this->assertContains($bought['code'], ['FIETS-PNT-0001', 'FIETS-PNT-0002']);

        // 16. The reward code is redeemed by a verified paid order using it; a problem report opens for the bought one.
        $this->orderEvent(['id' => 'PS-1003', 'reference' => 'XKB-1003', 'total_minor' => 6000, 'coupon_codes' => [$rewardCode],
            'customer' => ['first_name' => 'Anna', 'email' => 'anna@example.nl', 'phone' => null, 'contact_consent' => false]], 'evt-1003')->assertAccepted();
        $this->assertSame(VoucherStatus::Redeemed, Voucher::where('public_id', $claimed->json('data.voucher_id'))->sole()->status);
        $this->as($anna);
        $this->postJson('/api/v1/consumer/disputes', ['voucher_id' => $purchase->json('data.voucher_id'), 'reason' => 'code_invalid', 'description' => 'De webshop accepteert de code niet.'])
            ->assertCreated()->assertJsonPath('data.status', 'open');

        // 17. Super Admin sees the activity; audit chain, wallet ledger and inventory are consistent.
        $this->as($admin);
        $actions = collect($this->getJson('/api/v1/admin/activity?per_page=100')->assertOk()->json('data'))->pluck('action');
        foreach (['ownership.verified', 'workspace.approved'] as $action) {
            $this->assertContains($action, $actions, $action);
        }
        $this->artisan('shopbonus:audit-verify')->assertSuccessful();
        $this->assertSame([], app(WalletService::class)->reconcile());

        // Cross-module integrity: no duplicates, no negative balances, no orphans.
        $this->assertSame(3, WebshopOrder::count());
        $this->assertSame(1, OutboxMessage::where('type', 'answer24.contact_requested')->count());
        $this->assertSame(1, DB::table('affiliate_transactions')->count());
        $this->assertSame(0, DB::table('wallets')->where('available', '<', 0)->count());
        $this->assertSame(2, VoucherCode::whereIn('status', ['issued', 'redeemed'])->count());
        $this->assertSame(2, Voucher::whereNotNull('voucher_code_id')->distinct('voucher_code_id')->count('voucher_code_id'));
        $this->assertSame(0, Voucher::whereNotIn('webshop_id', Webshop::pluck('id'))->count());
        $this->assertGreaterThan(10, AuditEvent::count());
    }
}
