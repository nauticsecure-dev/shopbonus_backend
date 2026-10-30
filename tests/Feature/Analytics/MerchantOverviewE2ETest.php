<?php

namespace Tests\Feature\Analytics;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Models\AuditEvent;
use App\Models\MerchantNotification;
use App\Models\Offer;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\VoucherCode;
use App\Models\Webshop;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * Exactly 10 E2E scenarios for Trello #8 Merchant Overview Dashboard.
 * Hits authoritative workspace APIs the live frontend consumes.
 */
class MerchantOverviewE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $woon;

    private Webshop $fiets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Europe/Amsterdam'));
        $this->owner = User::factory()->create(['first_name' => 'Sanne']);
        $this->woon = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        $this->fiets = $this->activeWebshop($this->owner, ['name' => 'Fietsen Utrecht']);
        config(['shopbonus.notifications.low_inventory_threshold' => 20]);
    }

    private function membership(Webshop $shop)
    {
        return $shop->memberships()->where('user_id', $this->owner->id)->sole()->setRelation('webshop', $shop);
    }

    private function createOffer(Webshop $shop, array $terms = [], string $status = 'active', int $codes = 0): Offer
    {
        $offers = app(OfferService::class);
        $defaults = [
            'name' => '10% korting',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'min_order_minor' => 10000,
            'redemption_validity_days' => 90,
        ];
        $payload = [...$defaults, ...$terms];
        if (($payload['discount_type'] ?? null) === 'percentage' && ! array_key_exists('max_discount_minor', $payload)) {
            $payload['max_discount_minor'] = 1000;
        }
        $offer = $offers->create($this->membership($shop), OfferAcquisition::MerchantReward, $payload);
        if ($status === 'active') {
            $offer = $offers->transition($offer, OfferStatus::Active);
        } elseif ($status === 'paused') {
            $offer = $offers->transition($offer, OfferStatus::Active);
            $offer = $offers->transition($offer, OfferStatus::Paused);
        } elseif ($status === 'ended') {
            $offer = $offers->transition($offer, OfferStatus::Active);
            $offer = $offers->transition($offer, OfferStatus::Ended);
        }
        for ($i = 1; $i <= $codes; $i++) {
            $code = sprintf('W%s-%04d', $offer->id, $i);
            VoucherCode::create([
                'webshop_id' => $shop->id,
                'offer_id' => $offer->id,
                'code' => $code,
                'code_hash' => VoucherCode::hashFor($shop->id, $code),
                'code_last4' => substr($code, -4),
            ]);
        }

        return $offer->fresh(['currentVersion']);
    }

    private function dash(?Webshop $shop = null): string
    {
        return '/api/v1/workspaces/'.($shop ?? $this->woon)->public_id.'/dashboard';
    }

    /** E2E 1 — Merchant login and dashboard */
    public function test_e2e_01_merchant_dashboard_loads_real_backend_payload_for_woon_and_co(): void
    {
        $this->createOffer($this->woon, ['name' => '10% korting'], codes: 12);
        MerchantNotification::create([
            'public_id' => (string) Str::ulid(),
            'webshop_id' => $this->woon->id,
            'type' => 'new_claims',
            'severity' => 'info',
            'title' => '2 nieuwe claims',
            'body' => 'Vanochtend om 09:24',
            'dedupe_key' => 'new_claims:'.$this->woon->id.':demo',
        ]);

        Sanctum::actingAs($this->owner);
        $me = $this->getJson('/api/v1/me')->assertOk();
        $this->assertSame('workspace', $me->json('data.destination'));
        $this->assertContains('Woon & Co', array_column($me->json('data.workspaces'), 'name'));

        $dash = $this->getJson($this->dash())->assertOk()->json('data');
        $this->assertSame('Woon & Co', $dash['webshop']['name']);
        $this->assertSame('good_morning', $dash['greeting']);
        $this->assertArrayHasKey('active_offers', $dash['kpis']);
        $this->assertArrayHasKey('available_codes', $dash['kpis']);
        $this->assertArrayHasKey('issued_vouchers', $dash['kpis']);
        $this->assertArrayHasKey('claimed_vouchers', $dash['kpis']);
        $this->assertNotEmpty($dash['offers']);
        $this->assertGreaterThanOrEqual(1, $dash['notifications']['unread']);
        $this->assertSame(MerchantAnalyticsDefinitions(), $dash['definitions']);

        $claims = $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/analytics/claims?days=30')->assertOk()->json('data');
        $this->assertSame('Europe/Amsterdam', $claims['timezone']);
        $this->assertCount(30, $claims['days']);
    }

    /** E2E 2 — Active offer counting */
    public function test_e2e_02_active_offers_kpi_excludes_paused_and_ended_but_filters_remain(): void
    {
        $this->createOffer($this->woon, ['name' => 'Actief A']);
        $this->createOffer($this->woon, ['name' => 'Actief B']);
        $this->createOffer($this->woon, ['name' => 'Actief C']);
        $this->createOffer($this->woon, ['name' => 'Gepauzeerd'], status: 'paused');
        $this->createOffer($this->woon, ['name' => 'Verlopen'], status: 'ended');

        Sanctum::actingAs($this->owner);
        $dash = $this->getJson($this->dash())->assertOk();
        $this->assertSame(3, $dash->json('data.kpis.active_offers'));

        $active = $this->getJson($this->dash().'?status=active')->assertOk()->json('data.offers');
        $this->assertCount(3, $active);
        $this->assertTrue(collect($active)->every(fn ($o) => $o['status'] === 'active'));

        $paused = $this->getJson($this->dash().'?status=paused')->json('data.offers');
        $ended = $this->getJson($this->dash().'?status=ended')->json('data.offers');
        $this->assertCount(1, $paused);
        $this->assertCount(1, $ended);

        $all = $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/offers')->assertOk()->json('data');
        $this->assertCount(5, $all);
    }

    /** E2E 3 — Code inventory accuracy (established state rules) */
    public function test_e2e_03_available_codes_reconcile_with_inventory_after_lifecycle_moves(): void
    {
        $offer = $this->createOffer($this->woon, ['name' => 'Inventory'], codes: 100);
        $inventory = app(VoucherInventory::class);
        Sanctum::actingAs($this->owner);

        for ($i = 1; $i <= 10; $i++) {
            $inventory->reserve($offer, VoucherOrigin::MerchantReward, "reserve-{$i}");
        }
        $issued = [];
        for ($i = 1; $i <= 15; $i++) {
            $issued[] = $inventory->issue(
                $inventory->reserve($offer, VoucherOrigin::MerchantReward, "issue-{$i}"),
                User::factory()->create(['email_verified_at' => now()])
            );
        }
        for ($i = 0; $i < 5; $i++) {
            $inventory->redeemByMerchant($issued[$i], $this->owner->id, "ref-{$i}");
        }
        $available = VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->orderBy('id')->limit(3)->get();
        foreach ($available as $code) {
            $this->postJson('/api/v1/workspaces/'.$this->woon->public_id.'/codes/'.$code->public_id.'/invalidate', [
                'reason' => 'test',
            ])->assertOk();
        }

        // 100 − 10 reserved − 15 issued − 3 invalid = 72. Redeemed codes are a
        // subset of issued and must not be subtracted again from available.
        $expectedAvailable = 72;
        $dash = $this->getJson($this->dash())->assertOk();
        $this->assertSame($expectedAvailable, $dash->json('data.kpis.available_codes'));
        $db = VoucherCode::where('webshop_id', $this->woon->id)->where('status', VoucherCodeStatus::Available)->count();
        $this->assertSame($expectedAvailable, $db);
        $page = $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/offers/'.$offer->public_id.'/codes?status=available')->assertOk();
        $this->assertSame($expectedAvailable, $page->json('meta.total') ?? count($page->json('data')));
    }

    /** E2E 4 — Voucher issue versus claim */
    public function test_e2e_04_issued_versus_claimed_and_redeem_does_not_double_count(): void
    {
        $offer = $this->createOffer($this->woon, codes: 40);
        $inventory = app(VoucherInventory::class);
        $issued = [];
        for ($i = 1; $i <= 20; $i++) {
            $issued[] = $inventory->issue(
                $inventory->reserve($offer, VoucherOrigin::Answer24Claim, "claim-issue-{$i}"),
                User::factory()->create(['email_verified_at' => now()])
            );
        }
        for ($i = 0; $i < 8; $i++) {
            VoucherClaim::create([
                'webshop_id' => $this->woon->id,
                'voucher_id' => $issued[$i]->id,
                'token_hash' => hash('sha256', "tok-{$i}"),
                'token' => "tok-{$i}",
                'status' => 'claimed',
                'expires_at' => now()->addDays(90),
                'claimed_at' => now()->subMinutes(8 - $i),
            ]);
        }
        for ($i = 0; $i < 3; $i++) {
            $inventory->redeemByMerchant($issued[$i], $this->owner->id, "redeem-{$i}");
        }

        Sanctum::actingAs($this->owner);
        $kpis = $this->getJson($this->dash())->assertOk()->json('data.kpis');
        $this->assertSame(20, $kpis['issued_vouchers']);
        $this->assertSame(8, $kpis['claimed_vouchers']);
        $this->assertSame(3, $kpis['redeemed_vouchers']);
        $this->assertSame(20, Voucher::where('webshop_id', $this->woon->id)->whereNotNull('issued_at')->count());
    }

    /** E2E 5 — Offer navigation (API contract the Manage link uses) */
    public function test_e2e_05_manage_offer_returns_exact_offer_and_preserves_workspace_scope(): void
    {
        $offer = $this->createOffer($this->woon, [
            'name' => '10% korting',
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'min_order_minor' => 10000,
            'max_discount_minor' => 1000,
        ], codes: 25);

        Sanctum::actingAs($this->owner);
        $row = collect($this->getJson($this->dash())->json('data.offers'))->firstWhere('id', $offer->public_id);
        $this->assertNotNull($row);
        $this->assertSame('percentage', $row['type']);
        $this->assertSame(10000, $row['min_order_minor']);

        $detail = $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/offers/'.$offer->public_id)->assertOk()->json('data');
        $this->assertSame($offer->public_id, $detail['id']);
        $this->assertSame('active', $detail['status']);
        $this->assertSame(25, $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/offers/'.$offer->public_id.'/codes?status=available')->json('meta.total'));

        $this->postJson('/api/v1/workspaces/'.$this->woon->public_id.'/select')->assertOk();
        $me = $this->getJson('/api/v1/me')->assertOk()->json('data');
        $current = collect($me['workspaces'])->firstWhere('is_current', true);
        $this->assertSame($this->woon->public_id, $current['id']);
    }

    /** E2E 6 — Create and pause offer */
    public function test_e2e_06_create_activate_then_pause_updates_kpi_without_voiding_issued_vouchers(): void
    {
        Sanctum::actingAs($this->owner);
        $created = $this->postJson('/api/v1/workspaces/'.$this->woon->public_id.'/offers', [
            'acquisition' => 'merchant_reward',
            'name' => 'Nieuwe actie',
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'min_order_minor' => 5000,
            'max_discount_minor' => 500,
            'redemption_validity_days' => 60,
        ])->assertCreated()->json('data');

        $code = 'NEW-ACTIE-001';
        VoucherCode::create([
            'webshop_id' => $this->woon->id,
            'offer_id' => Offer::where('public_id', $created['id'])->value('id'),
            'code' => $code,
            'code_hash' => VoucherCode::hashFor($this->woon->id, $code),
            'code_last4' => '0001',
        ]);

        $this->postJson('/api/v1/workspaces/'.$this->woon->public_id.'/offers/'.$created['id'].'/activate')->assertOk();
        $offer = Offer::where('public_id', $created['id'])->sole();
        $voucher = app(VoucherInventory::class)->issue(
            app(VoucherInventory::class)->reserve($offer, VoucherOrigin::MerchantReward, 'keep-issued'),
            User::factory()->create(['email_verified_at' => now()])
        );

        $this->assertSame(1, $this->getJson($this->dash())->json('data.kpis.active_offers'));
        $this->postJson('/api/v1/workspaces/'.$this->woon->public_id.'/offers/'.$created['id'].'/pause')->assertOk();
        $this->assertSame(0, $this->getJson($this->dash())->json('data.kpis.active_offers'));
        $this->assertSame(1, $this->getJson($this->dash())->json('data.kpis.issued_vouchers'));
        $this->assertSame('issued', $voucher->fresh()->status->value);
    }

    /** E2E 7 — Low inventory alert */
    public function test_e2e_07_low_inventory_notification_badge_action_and_resolution(): void
    {
        config(['shopbonus.notifications.low_inventory_threshold' => 15]);
        $offer = $this->createOffer($this->woon, ['name' => '10% korting'], codes: 12);

        $this->artisan('shopbonus:merchant-notifications')->assertSuccessful();
        $this->artisan('shopbonus:merchant-notifications')->assertSuccessful();

        $notes = MerchantNotification::where('webshop_id', $this->woon->id)->where('type', 'low_inventory')->get();
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('12', $notes->first()->body);

        Sanctum::actingAs($this->owner);
        $dash = $this->getJson($this->dash())->assertOk()->json('data');
        $this->assertSame(1, $dash['notifications']['unread']);
        $this->assertSame($offer->public_id, $dash['notifications']['latest'][0]['subject']['id']);

        $before = MerchantNotification::where('webshop_id', $this->woon->id)->where('type', 'low_inventory')->count();
        for ($i = 13; $i <= 30; $i++) {
            $code = sprintf('TOPUP-%04d', $i);
            VoucherCode::create([
                'webshop_id' => $this->woon->id,
                'offer_id' => $offer->id,
                'code' => $code,
                'code_hash' => VoucherCode::hashFor($this->woon->id, $code),
                'code_last4' => substr($code, -4),
            ]);
        }
        $this->assertSame(0, app(\App\Services\Analytics\MerchantNotifications::class)->forWebshop($this->woon, CarbonImmutable::now()));
        $this->assertSame($before, MerchantNotification::where('webshop_id', $this->woon->id)->where('type', 'low_inventory')->count());
        $this->assertGreaterThanOrEqual(15, VoucherCode::where('offer_id', $offer->id)->where('status', VoucherCodeStatus::Available)->count());
    }

    /** E2E 8 — Claim chart integrity */
    public function test_e2e_08_claims_chart_counts_valid_claims_once_with_timezone_and_range(): void
    {
        $offer = $this->createOffer($this->woon, codes: 10);
        $inventory = app(VoucherInventory::class);
        $dates = ['2026-10-10', '2026-10-15', '2026-10-20'];
        foreach ($dates as $i => $day) {
            $v = $inventory->issue(
                $inventory->reserve($offer, VoucherOrigin::Answer24Claim, "chart-{$day}"),
                User::factory()->create(['email_verified_at' => now()])
            );
            VoucherClaim::create([
                'webshop_id' => $this->woon->id,
                'voucher_id' => $v->id,
                'token_hash' => hash('sha256', "chart-{$day}"),
                'token' => "chart-{$day}",
                'status' => 'claimed',
                'expires_at' => now()->addDays(90),
                'claimed_at' => CarbonImmutable::parse($day.' 14:00:00', 'Europe/Amsterdam')->utc(),
            ]);
        }
        // Unsuccessful / incomplete claim attempts must not count.
        $openVoucher = $inventory->reserve($offer, VoucherOrigin::Answer24Claim, 'chart-open');
        VoucherClaim::create([
            'webshop_id' => $this->woon->id,
            'voucher_id' => $openVoucher->id,
            'token_hash' => hash('sha256', 'pending'),
            'token' => 'pending',
            'status' => 'open',
            'expires_at' => now()->addDays(1),
            'claimed_at' => null,
        ]);

        Sanctum::actingAs($this->owner);
        $series = $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/analytics/claims?days=30')->assertOk()->json('data');
        $byDate = array_column($series['days'], null, 'date');
        foreach ($dates as $day) {
            $this->assertSame(1, $byDate[$day]['claims']);
        }
        $this->assertSame(3, $series['totals']['claims']);

        $window = $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/analytics/claims?from=2026-10-15&to=2026-10-20')->assertOk()->json('data');
        $this->assertSame(2, $window['totals']['claims']);
        $this->assertSame(['2026-10-15', '2026-10-20'], [$window['days'][0]['date'], $window['days'][array_key_last($window['days'])]['date']]);
    }

    /** E2E 9 — Multiple webshop isolation */
    public function test_e2e_09_webshop_switch_isolates_data_and_unauthorized_shop_is_denied_with_audit(): void
    {
        $this->createOffer($this->woon, ['name' => 'Woon actie'], codes: 5);
        $this->createOffer($this->fiets, ['name' => 'Fiets actie'], codes: 9);
        MerchantNotification::create([
            'public_id' => (string) Str::ulid(),
            'webshop_id' => $this->woon->id,
            'type' => 'new_claims',
            'severity' => 'info',
            'title' => 'Woon melding',
            'body' => 'alleen woon',
            'dedupe_key' => 'woon-only',
        ]);

        Sanctum::actingAs($this->owner);
        $woonDash = $this->getJson($this->dash($this->woon))->assertOk()->json('data');
        $fietsDash = $this->getJson($this->dash($this->fiets))->assertOk()->json('data');
        $this->assertSame(5, $woonDash['kpis']['available_codes']);
        $this->assertSame(9, $fietsDash['kpis']['available_codes']);
        $this->assertSame('Woon & Co', $woonDash['webshop']['name']);
        $this->assertSame('Fietsen Utrecht', $fietsDash['webshop']['name']);
        $this->assertGreaterThanOrEqual(1, $woonDash['notifications']['unread']);
        $this->assertSame(0, $fietsDash['notifications']['unread']);

        $outsiderShop = $this->activeWebshop(User::factory()->create(), ['name' => 'Webshop C']);
        $this->getJson('/api/v1/workspaces/'.$outsiderShop->public_id.'/dashboard')->assertNotFound();
        $this->assertSame(1, AuditEvent::where('action', 'workspace.access_denied')->count());
    }

    /** E2E 10 — Failures, recovery and performance */
    public function test_e2e_10_validation_errors_recovery_and_large_inventory_performance(): void
    {
        $offer = $this->createOffer($this->woon, codes: 250);
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/analytics/claims?days=12')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $ok = $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/analytics/claims?days=30')->assertOk();
        $this->assertNotEmpty($ok->headers->get('X-Request-Id') ?? $ok->json('error.request_id'));

        $start = microtime(true);
        $dash = $this->getJson($this->dash())->assertOk()->json('data');
        $elapsedMs = (microtime(true) - $start) * 1000;
        $this->assertSame(250, $dash['kpis']['available_codes']);
        $this->assertLessThan(2000, $elapsedMs, 'Dashboard aggregate should stay fast with large inventory');

        // Concurrent claim: two successful claims must not double in totals.
        $inventory = app(VoucherInventory::class);
        $v1 = $inventory->issue($inventory->reserve($offer, VoucherOrigin::Answer24Claim, 'perf-1'), User::factory()->create(['email_verified_at' => now()]));
        $v2 = $inventory->issue($inventory->reserve($offer, VoucherOrigin::Answer24Claim, 'perf-2'), User::factory()->create(['email_verified_at' => now()]));
        foreach ([$v1, $v2] as $i => $v) {
            VoucherClaim::create([
                'webshop_id' => $this->woon->id,
                'voucher_id' => $v->id,
                'token_hash' => hash('sha256', "perf-{$i}"),
                'token' => "perf-{$i}",
                'status' => 'claimed',
                'expires_at' => now()->addDay(),
                'claimed_at' => now(),
            ]);
        }
        $this->assertSame(2, $this->getJson($this->dash())->json('data.kpis.claimed_vouchers'));
        $this->assertSame(2, $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/analytics/claims?days=7')->json('data.totals.claims'));

        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->dash())->assertNotFound();
    }
}

/** @return array<string, string> */
function MerchantAnalyticsDefinitions(): array
{
    return \App\Services\Analytics\MerchantAnalytics::DEFINITIONS;
}
