<?php

namespace Tests\Feature\Offers;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Enums\VoucherStatus;
use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\SponsorshipObligation;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Funding\MerchantFunding;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\SignsWebhooks;
use Tests\TestCase;

/**
 * Exactly 10 E2E scenarios for the merchant Offers & Discount Codes page (Trello #7).
 * Hits the same workspace APIs the live /dashboard/offers UI consumes.
 */
class OffersCodesE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase, SignsWebhooks;

    private User $owner;

    private Webshop $woon;

    private const TEN_PERCENT = [
        'acquisition' => 'merchant_reward',
        'name' => 'Woon & Co: 10% korting',
        'public_description' => 'Op je volgende aankoop vanaf €100',
        'discount_type' => 'percentage',
        'discount_value' => 1000,
        'min_order_minor' => 10000,
        'max_discount_minor' => 1000,
        'claim_validity_days' => 90,
        'redemption_validity_days' => 90,
        'categories' => ['wonen'],
        'combinable' => false,
        'allocation_limit' => 500,
    ];

    /** @var list<string> */
    private array $addresses = ['93.184.216.34'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['first_name' => 'Sanne']);
        $this->woon = $this->activeWebshop($this->owner, [
            'name' => 'Woon & Co',
            'verified_domain' => 'woonenco.nl',
        ]);
        Sanctum::actingAs($this->owner);
        $this->app->bind(HostResolver::class, fn () => new class($this->addresses) implements HostResolver
        {
            public function __construct(private array $ips) {}

            public function addresses(string $host): array
            {
                return $this->ips;
            }

            public function txtRecords(string $host): array
            {
                return [];
            }
        });
    }

    private function url(string $suffix, ?Webshop $shop = null): string
    {
        return '/api/v1/workspaces/'.($shop ?? $this->woon)->public_id.$suffix;
    }

    private function membership(?Webshop $shop = null)
    {
        $shop ??= $this->woon;

        return $shop->memberships()->where('user_id', $this->owner->id)->sole()->setRelation('webshop', $shop);
    }

    private function seedCodes(Offer $offer, array $codes): void
    {
        $id = $this->postJson($this->url("/offers/{$offer->public_id}/code-imports"), ['codes' => implode("\n", $codes)])
            ->assertCreated()->json('data.id');
        $this->postJson($this->url("/code-imports/{$id}/commit"))->assertOk();
    }

    /** E2E 1 — Offer selection refreshes terms/codes/counters without leaking other offers. */
    public function test_e2e1_offer_selection_isolates_terms_inventory_and_counters(): void
    {
        $ids = [];
        foreach (['A' => 500, 'B' => 1000, 'C' => 1500] as $label => $value) {
            $id = $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, 'name' => "Offer {$label}", 'discount_value' => $value])
                ->assertCreated()->json('data.id');
            $ids[$label] = $id;
            $this->seedCodes(Offer::where('public_id', $id)->sole(), ["OFF{$label}-0001", "OFF{$label}-0002"]);
        }

        $list = $this->getJson($this->url('/offers'))->assertOk()->json('data');
        $this->assertCount(3, $list);

        foreach ($ids as $label => $id) {
            $detail = $this->getJson($this->url("/offers/{$id}"))->assertOk();
            $detail->assertJsonPath('data.terms.name', "Offer {$label}");
            $sheet = $this->getJson($this->url("/offers/{$id}/codes"))->assertOk();
            $this->assertSame(2, $sheet->json('meta.counters.total'));
            $this->assertSame(2, $sheet->json('meta.counters.available'));
            $this->assertCount(2, $sheet->json('data'));
            foreach ($sheet->json('data') as $row) {
                $this->assertMatchesRegularExpression('/^\*{4}.{4}$/', $row['code']);
            }
            $last4 = collect($sheet->json('data'))->pluck('code')->all();
            $this->assertContains('****0001', $last4);
            $this->assertContains('****0002', $last4);
        }
    }

    /** E2E 2 — Create 10% / €100 / €10 / 90-day offer; reopen persists; cost preview from FeeCalculator. */
    public function test_e2e2_create_ten_percent_offer_persists_and_feeds_eligibility_preview(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.terms.summary', '10% from €100.00, maximum €10.00')
            ->assertJsonPath('data.cost_preview.per_voucher.merchant_commitment_minor', 1300)
            ->json('data.id');

        $reopened = $this->getJson($this->url("/offers/{$id}"))->assertOk();
        foreach ([
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'min_order_minor' => 10000,
            'max_discount_minor' => 1000,
            'claim_validity_days' => 90,
            'redemption_validity_days' => 90,
        ] as $field => $value) {
            $reopened->assertJsonPath("data.terms.{$field}", $value);
        }
        $reopened->assertJsonPath('data.cost_preview.fee_rate_bps', 3000)
            ->assertJsonPath('data.cost_preview.per_voucher', [
                'reward_minor' => 1000,
                'fee_minor' => 300,
                'merchant_commitment_minor' => 1300,
            ]);
    }

    /** E2E 3 — Issued vouchers keep original terms after offer edit; new issuance uses version 2. */
    public function test_e2e3_edit_protection_keeps_issued_voucher_terms(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $offer = Offer::where('public_id', $id)->sole();
        $this->seedCodes($offer, ['PROT-0001', 'PROT-0002']);
        $this->postJson($this->url("/offers/{$id}/activate"))->assertOk();

        $inventory = app(VoucherInventory::class);
        $issued = $inventory->issue(
            $inventory->reserve($offer->fresh(), VoucherOrigin::MerchantReward, 'order:protect:1'),
            User::factory()->create()
        );

        $this->patchJson($this->url("/offers/{$id}"), ['discount_value' => 1500])->assertOk()
            ->assertJsonPath('data.terms.version', 2)
            ->assertJsonPath('data.terms.discount_value', 1500);

        $next = $inventory->reserve($offer->fresh(), VoucherOrigin::MerchantReward, 'order:protect:2');
        $this->assertSame(1000, $issued->fresh()->terms_snapshot['discount_value']);
        $this->assertSame(1000, $issued->fresh()->offerVersion->discount_value);
        $this->assertSame(1500, $next->offerVersion->discount_value);
        $this->assertSame([1, 2], OfferVersion::where('offer_id', $offer->id)->orderBy('version')->pluck('version')->all());
    }

    /** E2E 4 — Paste + CSV import preview rejects duplicates/invalid; batch audit + accurate totals. */
    public function test_e2e4_import_validation_preview_commit_and_batch_audit(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        VoucherCode::create([
            'webshop_id' => $this->woon->id,
            'offer_id' => Offer::where('public_id', $id)->value('id'),
            'code' => 'EXISTING01',
            'code_hash' => VoucherCode::hashFor($this->woon->id, 'EXISTING01'),
            'code_last4' => 'NG01',
        ]);

        $paste = "WOON-0001\nWOON-0002\n  WOON-0003  \nwoon-0001\nbad code!\n\nWOON-0004\nWOON-0005\nWOON-0006\nexisting01\nWOON-0007";
        $preview = $this->postJson($this->url("/offers/{$id}/code-imports"), ['codes' => $paste])->assertCreated()
            ->assertJsonPath('data.totals', [
                'submitted' => 10,
                'valid' => 7,
                'invalid' => 1,
                'duplicate_in_upload' => 1,
                'duplicate_existing' => 1,
            ]);
        $this->assertSame(1, VoucherCode::count());

        $this->postJson($this->url('/code-imports/'.$preview->json('data.id').'/commit'))->assertOk()
            ->assertJsonPath('data.totals.imported', 7);

        $csv = UploadedFile::fake()->createWithContent('codes.csv', "code\nCSV-0001\nCSV-0002\nCSV-0001\n");
        $this->post($this->url("/offers/{$id}/code-imports"), ['file' => $csv], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'file')
            ->assertJsonPath('data.totals.valid', 2)
            ->assertJsonPath('data.totals.duplicate_in_upload', 1);

        $this->assertSame(
            ['codes.import_previewed', 'codes.import_committed', 'codes.import_previewed'],
            AuditEvent::where('action', 'like', 'codes.import%')->orderBy('id')->pluck('action')->all()
        );
    }

    /** E2E 5 — Final available code: only one reservation wins; counters stay accurate. */
    public function test_e2e5_final_code_cannot_be_double_reserved(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $offer = Offer::where('public_id', $id)->sole();
        $this->seedCodes($offer, ['LAST-0001']);
        $this->postJson($this->url("/offers/{$id}/activate"))->assertOk();

        $inventory = app(VoucherInventory::class);
        $first = $inventory->reserve($offer->fresh(), VoucherOrigin::MerchantReward, 'race:a');
        try {
            $inventory->reserve($offer->fresh(), VoucherOrigin::MerchantReward, 'race:b');
            $this->fail('Second reservation of the final code must fail.');
        } catch (ApiException $e) {
            $this->assertSame('offer_out_of_stock', $e->errorCode);
        }

        $this->assertSame(1, Voucher::count());
        $this->assertSame(VoucherCodeStatus::Reserved, VoucherCode::sole()->status);
        $sheet = $this->getJson($this->url("/offers/{$id}/codes"))->assertOk();
        $this->assertSame([
            'total' => 1,
            'available' => 0,
            'reserved' => 1,
            'issued' => 0,
            'redeemed' => 0,
            'expired' => 0,
            'invalid' => 0,
            'cancelled' => 0,
        ], $sheet->json('meta.counters'));
        $this->assertSame($first->public_id, $sheet->json('data.0.assigned.voucher_id'));
    }

    /** E2E 6 — Available → Reserved → Claimed/Issued → Redeemed; repeat redeem rejected; audit trail. */
    public function test_e2e6_voucher_lifecycle_and_repeat_redemption_rejected(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $offer = Offer::where('public_id', $id)->sole();
        $this->seedCodes($offer, ['LIFE-AAAA-0001']);
        $this->postJson($this->url("/offers/{$id}/activate"))->assertOk();

        $inventory = app(VoucherInventory::class);
        $consumer = User::factory()->create();
        $voucher = $inventory->reserve($offer->fresh(), VoucherOrigin::Answer24Claim, 'handoff:life:1');
        $this->assertSame(VoucherStatus::Reserved, $voucher->status);
        $this->assertSame(VoucherCodeStatus::Reserved, VoucherCode::sole()->status);

        $voucher = $inventory->issue($voucher, $consumer);
        $this->assertSame(VoucherStatus::Issued, $voucher->status);
        $this->assertSame(VoucherCodeStatus::Issued, VoucherCode::sole()->status);

        $conn = $this->postJson($this->url('/connections'), ['platform' => 'woocommerce'])->assertCreated();
        $path = (string) parse_url($conn->json('data.setup.webhook_url'), PHP_URL_PATH);
        $secret = $conn->json('data.secrets.webhook_secret');
        $body = json_encode($this->wooOrder([
            'id' => 9001,
            'total' => '102.00',
            'coupon_lines' => [['id' => 1, 'code' => 'life-aaaa-0001', 'discount' => '10.00']],
        ]));
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($this->wooHeaders($body, $secret, 'life-1') as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $this->call('POST', $path, [], [], [], $server, $body)->assertStatus(202);

        $voucher->refresh();
        $this->assertSame(VoucherStatus::Redeemed, $voucher->status);
        $this->assertSame(VoucherCodeStatus::Redeemed, VoucherCode::sole()->status);
        $this->assertNotNull($voucher->redeemed_order_id);

        try {
            $inventory->redeem($voucher, WebshopOrder::sole());
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame('voucher_not_redeemable', $e->errorCode);
        }

        $history = $this->getJson($this->url('/codes/'.VoucherCode::sole()->public_id.'/history'))->assertOk()->json('data');
        $this->assertNotEmpty($history);
        $types = collect($history)->pluck('type')->all();
        $this->assertContains('code_redeemed', $types);
    }

    /** E2E 7 — Woo coupon API verifies match; unsupported platform shows merchant verification required. */
    public function test_e2e7_real_webshop_coupon_compatibility_and_unsupported_fallback(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $offer = Offer::where('public_id', $id)->sole();
        $this->seedCodes($offer, ['WOON-VERIFY-01']);
        $code = VoucherCode::sole();

        $this->postJson($this->url('/connections'), [
            'platform' => 'woocommerce',
            'store_url' => 'https://woonenco.nl',
            'consumer_key' => 'ck_SYNTHETIC_read_key',
            'consumer_secret' => 'cs_SYNTHETIC_read_secret',
        ])->assertCreated();

        Http::fake(function (Request $request) {
            $this->assertStringContainsString('/wp-json/wc/v3/coupons', $request->url());
            $this->assertSame('WOON-VERIFY-01', $request['code'] ?? null);

            return Http::response([[
                'id' => 55,
                'code' => 'WOON-VERIFY-01',
                'discount_type' => 'percent',
                'amount' => '10.00',
                'minimum_amount' => '100.00',
                'maximum_amount' => '10.00',
                'date_expires' => null,
                'usage_limit' => 1,
            ]], 200, ['X-WP-TotalPages' => '1']);
        });

        $verified = $this->postJson($this->url("/codes/{$code->public_id}/platform-validation"), ['mode' => 'provider_check'])
            ->assertOk()
            ->assertJsonPath('data.platform_validation', 'verified')
            ->assertJsonPath('verification.detail.reason', 'provider_match');
        $this->assertSame('verified', $verified->json('data.platform_validation'));

        // Unsupported platform → never advertise Verified.
        $otherOwner = User::factory()->create();
        $other = $this->activeWebshop($otherOwner, ['name' => 'Wix Shop', 'verified_domain' => 'wixshop.nl']);
        Sanctum::actingAs($otherOwner);
        $offerB = $this->postJson($this->url('/offers', $other), [...self::TEN_PERCENT, 'name' => 'Wix deal'])->json('data.id');
        $batch = $this->postJson($this->url("/offers/{$offerB}/code-imports", $other), ['codes' => 'WIX-CODE-01'])->json('data.id');
        $this->postJson($this->url("/code-imports/{$batch}/commit", $other))->assertOk();
        $wixCode = VoucherCode::where('webshop_id', $other->id)->sole();
        WebshopConnection::create([
            'webshop_id' => $other->id,
            'platform' => 'wix',
            'method' => 'webhook',
            'status' => 'connected',
            'webhook_token' => str_repeat('w', 40),
            'created_by' => $otherOwner->id,
            'connected_at' => now(),
        ]);

        $this->postJson($this->url("/codes/{$wixCode->public_id}/platform-validation", $other), ['mode' => 'provider_check'])
            ->assertOk()
            ->assertJsonPath('data.platform_validation', 'unsupported_integration')
            ->assertJsonPath('verification.detail.reason', 'coupon_api_unsupported');

        // Manual confirmation path for unsupported integrations.
        $this->postJson($this->url("/codes/{$wixCode->public_id}/platform-validation", $other), [
            'mode' => 'manual_confirm',
            'note' => 'Checked in Wix admin — code applies at checkout.',
        ])->assertOk()->assertJsonPath('data.platform_validation', 'verified');
    }

    /** E2E 8 — Eligible €102 order rewards once; funding reserved; replay creates no second reward/charge. */
    public function test_e2e8_wallet_and_order_integration_idempotent_reward(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $offer = Offer::where('public_id', $id)->sole();
        $this->seedCodes($offer, ['FUND-0001', 'FUND-0002']);
        $this->postJson($this->url("/offers/{$id}/activate"))->assertOk();

        $fundingBefore = app(MerchantFunding::class)->wallet($this->woon)->fresh()->available;
        $inventory = app(VoucherInventory::class);
        $key = 'order:102:offer:'.$offer->id;
        $first = $inventory->reserve($offer->fresh(), VoucherOrigin::MerchantReward, $key);
        $replay = $inventory->reserve($offer->fresh(), VoucherOrigin::MerchantReward, $key);

        $this->assertTrue($first->is($replay));
        $this->assertSame(1, Voucher::count());
        $this->assertSame(1, SponsorshipObligation::count());
        $fundingAfter = app(MerchantFunding::class)->wallet($this->woon)->fresh()->available;
        $this->assertSame(1300, $fundingBefore - $fundingAfter); // €10 + 30% once
        $this->assertSame(1, VoucherCode::where('status', VoucherCodeStatus::Reserved)->count());
        $this->assertSame(1, VoucherCode::where('status', VoucherCodeStatus::Available)->count());
    }

    /** E2E 9 — 500 codes, three batches: search/sort/pagination/filters/export + date filter. */
    public function test_e2e9_filtering_pagination_and_authorized_export(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $offer = Offer::where('public_id', $id)->sole();

        $batchIds = [];
        foreach ([200, 200, 100] as $i => $count) {
            $prefix = 'B'.($i + 1);
            $lines = array_map(fn ($n) => sprintf('%s-%04d', $prefix, $n), range(1, $count));
            $batchId = $this->postJson($this->url("/offers/{$id}/code-imports"), ['codes' => implode("\n", $lines)])
                ->assertCreated()->json('data.id');
            $this->postJson($this->url("/code-imports/{$batchId}/commit"))->assertOk();
            $batchIds[] = $batchId;
        }

        $this->postJson($this->url("/offers/{$id}/activate"))->assertOk();
        $inventory = app(VoucherInventory::class);
        foreach (range(1, 3) as $i) {
            $inventory->issue($inventory->reserve($offer->fresh(), VoucherOrigin::MerchantReward, "f{$i}"), User::factory()->create());
        }

        $sheet = $this->getJson($this->url("/offers/{$id}/codes?per_page=50"))->assertOk();
        $this->assertSame(500, $sheet->json('meta.counters.total'));
        $this->assertSame(497, $sheet->json('meta.counters.available'));
        $this->assertSame(3, $sheet->json('meta.counters.issued'));
        $this->assertCount(50, $sheet->json('data'));

        $this->assertSame(200, $this->getJson($this->url("/offers/{$id}/codes?batch={$batchIds[1]}"))->json('meta.total'));
        $this->assertSame(3, $this->getJson($this->url("/offers/{$id}/codes?status=issued"))->json('meta.total'));
        $this->assertCount(1, $this->getJson($this->url("/offers/{$id}/codes?search=b1-0150"))->json('data'));

        $ranged = $this->getJson($this->url("/offers/{$id}/codes").'?'.http_build_query([
            'added_from' => now()->subHour()->utc()->format('Y-m-d H:i:s'),
            'added_to' => now()->addHour()->utc()->format('Y-m-d H:i:s'),
        ]))->assertOk();
        $this->assertSame(500, $ranged->json('meta.total'));

        $csv = $this->get($this->url("/offers/{$id}/codes/export?batch={$batchIds[0]}&status=available"))->assertOk()->streamedContent();
        // First B1 codes may be issued; remaining available B1 rows must export, never B2.
        $this->assertStringContainsString('B1-0200', $csv);
        $this->assertStringNotContainsString('B2-0001', $csv);
        $this->assertSame(1, AuditEvent::where('action', 'codes.exported')->count());
    }

    /** E2E 10 — Tenant isolation 403/404, failed upload, sync error, no duplicate codes or wallet side-effects. */
    public function test_e2e10_permissions_and_operational_failures(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $this->seedCodes(Offer::where('public_id', $id)->sole(), ['PRIV-0001']);
        $code = VoucherCode::sole();
        $fundingBefore = app(MerchantFunding::class)->wallet($this->woon)->fresh()->available;

        $intruder = User::factory()->create();
        $intruderShop = $this->activeWebshop($intruder, ['name' => 'Other Shop']);
        Sanctum::actingAs($intruder);
        $this->getJson($this->url("/offers/{$id}/codes", $intruderShop))->assertNotFound();
        $this->postJson($this->url("/codes/{$code->public_id}/reveal", $intruderShop))->assertNotFound();
        $this->getJson($this->url("/offers/{$id}/codes"))->assertNotFound();

        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/offers/{$id}/code-imports"), [])->assertStatus(422);
        $this->assertSame(1, VoucherCode::count());

        // Revoked / missing credentials → sync_error, not Verified; no wallet movement.
        $this->postJson($this->url('/connections'), [
            'platform' => 'woocommerce',
            'store_url' => 'https://woonenco.nl',
            'consumer_key' => 'ck_SYNTHETIC_read_key',
            'consumer_secret' => 'cs_SYNTHETIC_read_secret',
        ])->assertCreated();
        Http::fake(fn () => Http::response(['error' => 'unauthorized'], 401));
        $sync = $this->postJson($this->url("/codes/{$code->public_id}/platform-validation"), ['mode' => 'provider_check'])
            ->assertOk()
            ->assertJsonPath('data.platform_validation', 'sync_error');
        $this->assertNotEmpty($sync->headers->get('X-Request-Id'));

        $this->assertSame($fundingBefore, app(MerchantFunding::class)->wallet($this->woon)->fresh()->available);
        $this->assertSame(1, VoucherCode::where('code_hash', VoucherCode::hashFor($this->woon->id, 'PRIV-0001'))->count());
        $this->assertSame(1, AuditEvent::where('action', 'codes.platform_validated')->count());

        $employee = User::factory()->create();
        $this->woon->memberships()->create([
            'user_id' => $employee->id,
            'role' => MembershipRole::Employee,
            'permissions' => ['vouchers.view'],
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($employee);
        $this->getJson($this->url("/offers/{$id}/codes"))->assertOk();
        $this->postJson($this->url("/codes/{$code->public_id}/reveal"))->assertForbidden();
        $this->get($this->url("/offers/{$id}/codes/export"), ['Accept' => 'application/json'])->assertForbidden();
    }
}
