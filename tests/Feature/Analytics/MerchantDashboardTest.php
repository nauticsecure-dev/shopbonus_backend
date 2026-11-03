<?php

namespace Tests\Feature\Analytics;

use App\Enums\MembershipRole;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Enums\VoucherOrigin;
use App\Models\MerchantNotification;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherClaim;
use App\Models\VoucherCode;
use App\Models\VoucherCodeBatch;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Analytics\MerchantAnalytics;
use App\Services\Analytics\MerchantNotifications;
use App\Services\Funding\MerchantFunding;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationInbox;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B13: merchant overview (Trello #8), lifecycle chart, notifications and
 * the owner's combined dashboard.
 */
class MerchantDashboardTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $woon;

    private Webshop $fiets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Europe/Amsterdam'));
        $this->owner = User::factory()->create();
        $this->woon = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        $this->fiets = $this->activeWebshop($this->owner, ['name' => 'Fietsen Utrecht']);
    }

    private function offer(Webshop $shop, int $codes, string $status = 'active', array $terms = []): Offer
    {
        $offers = app(OfferService::class);
        $offer = $offers->create($shop->memberships()->where('user_id', $this->owner->id)->sole()->setRelation('webshop', $shop), OfferAcquisition::MerchantReward,
            ['name' => "Korting {$shop->name}", 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 5000, 'redemption_validity_days' => 30, ...$terms]);
        if ($status === 'active') {
            $offer = $offers->transition($offer, OfferStatus::Active);
        }
        for ($i = 1; $i <= $codes; $i++) {
            $code = "C{$offer->id}-{$i}";
            VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $code, 'code_hash' => VoucherCode::hashFor($shop->id, $code), 'code_last4' => substr($code, -4)]);
        }

        return $offer;
    }

    public function test_card8_kpis_follow_one_definition_and_reconcile_with_the_inventory(): void
    {
        $offer = $this->offer($this->woon, 5);
        $this->offer($this->woon, 2, status: 'draft');
        $inventory = app(VoucherInventory::class);
        $issued = $inventory->issue($inventory->reserve($offer, VoucherOrigin::MerchantReward, 'o1'), User::factory()->create());
        $inventory->reserve($offer, VoucherOrigin::MerchantReward, 'o2'); // reserved only
        $inventory->redeemByMerchant($issued, $this->owner->id, null);
        VoucherClaim::create(['webshop_id' => $this->woon->id, 'voucher_id' => $issued->id, 'token_hash' => str_repeat('a', 64), 'token' => 'x', 'status' => 'claimed', 'expires_at' => now()->addDays(90), 'claimed_at' => now()]);

        Sanctum::actingAs($this->owner);
        $dash = $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/dashboard")->assertOk();
        $this->assertSame([
            'active_offers' => 1, 'reserved_codes' => 1, 'expired_vouchers' => 0, 'expired_offers' => 0,
            'available_codes' => 5, 'issued_vouchers' => 1, 'claimed_vouchers' => 1, 'redeemed_vouchers' => 1,
            'scheduled_offers' => 0, 'paused_offers' => 0, 'open_disputes' => 0,
        ], $dash->json('data.kpis'));
        // "Available codes" equals the inventory page's available filter.
        $inventoryCount = $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/offers/{$offer->public_id}/codes?status=available")->json('meta.total')
            ?? count($this->getJson("/api/v1/workspaces/{$this->woon->public_id}/offers/{$offer->public_id}/codes?status=available")->json('data'));
        $this->assertSame(3, $inventoryCount); // 5 created on the active offer minus 2 taken
        $this->assertSame(VoucherCode::where('webshop_id', $this->woon->id)->where('status', VoucherCodeStatus::Available)->count(), $dash->json('data.kpis.available_codes'));
        $this->assertSame('good_morning', $dash->json('data.greeting'));
        $this->assertSame(['active', 'draft'], array_column($dash->json('data.offers'), 'status'));
        $this->assertSame(['low_inventory'], $dash->json('data.offers.0.attention'));

        // Switching webshop: nothing of Woon & Co.
        $other = $this->getJson("/api/v1/workspaces/{$this->fiets->public_id}/dashboard")->assertOk();
        $this->assertSame([0, 0, 0], [$other->json('data.kpis.active_offers'), $other->json('data.kpis.issued_vouchers'), $other->json('data.kpis.available_codes')]);
        $this->assertSame([], $other->json('data.offers'));
    }

    public function test_card8_claims_chart_uses_the_business_timezone_and_zero_fills(): void
    {
        $offer = $this->offer($this->woon, 3);
        $inventory = app(VoucherInventory::class);
        $v = $inventory->issue($inventory->reserve($offer, VoucherOrigin::Answer24Claim, 'c1'), User::factory()->create());
        // 23:30 UTC on the 22nd is already the 23rd in Amsterdam (summer time).
        VoucherClaim::create(['webshop_id' => $this->woon->id, 'voucher_id' => $v->id, 'token_hash' => str_repeat('b', 64), 'token' => 'x', 'status' => 'claimed',
            'expires_at' => now()->addDays(90), 'claimed_at' => CarbonImmutable::parse('2026-10-22 23:30:00', 'UTC')]);

        Sanctum::actingAs($this->owner);
        $series = $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/analytics/claims?days=7")->assertOk()->json('data');
        $this->assertSame('Europe/Amsterdam', $series['timezone']);
        $this->assertCount(7, $series['days']);
        $this->assertSame(['2026-10-18', '2026-10-24'], [$series['days'][0]['date'], $series['days'][6]['date']]);
        $byDate = array_column($series['days'], null, 'date');
        $this->assertSame([0, 1, 0], [$byDate['2026-10-22']['claims'], $byDate['2026-10-23']['claims'], $byDate['2026-10-24']['claims']]);
        $this->assertSame(1, $byDate['2026-10-24']['issued']); // issuance is a separate series
        $this->assertSame(['claims' => 1, 'issued' => 1, 'redeemed' => 0], $series['totals']);

        $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/analytics/claims?days=12")->assertUnprocessable();
        $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/analytics/claims?from=2026-10-01&to=2026-10-05")->assertOk()->assertJsonCount(5, 'data.days');
        $this->getJson("/api/v1/workspaces/{$this->fiets->public_id}/analytics/claims?days=7")->assertJsonPath('data.totals.claims', 0);
    }

    public function test_notifications_are_deduplicated_scoped_and_markable(): void
    {
        $offer = $this->offer($this->woon, 3); // below the low-inventory threshold
        $this->offer($this->woon, 50, terms: ['ends_at' => now()->addDays(3)->toIso8601String()]);

        $this->artisan('shopbonus:merchant-notifications')->assertSuccessful();
        $this->artisan('shopbonus:merchant-notifications')->assertSuccessful(); // same situation: nothing new
        $this->assertSame(['low_inventory', 'offer_expiring'], MerchantNotification::where('webshop_id', $this->woon->id)->orderBy('type')->pluck('type')->all());

        // Funding drained: rewards cannot be funded.
        $wallet = app(MerchantFunding::class)->wallet($this->woon);
        app(WalletService::class)->adjust($wallet, -$wallet->available, 'drain', 'Test drain', $this->owner->id);
        $this->artisan('shopbonus:merchant-notifications')->assertSuccessful();
        $this->assertSame(2, MerchantNotification::where('webshop_id', $this->woon->id)->where('type', 'low_funding')->count()); // one per affected offer

        Sanctum::actingAs($this->owner);
        $list = $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/notifications?unread=1")->assertOk();
        $this->assertSame(4, $list->json('meta.unread'));
        $first = $list->json('data.0.id');
        $this->postJson("/api/v1/workspaces/{$this->woon->public_id}/notifications/{$first}/read")->assertOk()->assertJsonPath('data.read', true);
        $this->assertSame(3, $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/dashboard")->json('data.notifications.unread'));
        $this->postJson("/api/v1/workspaces/{$this->woon->public_id}/notifications/read-all")->assertOk()->assertJsonPath('data.marked_read', 3);

        // Not visible from another workspace.
        $this->postJson("/api/v1/workspaces/{$this->fiets->public_id}/notifications/{$first}/read")->assertNotFound();
        $this->assertSame(0, $this->getJson("/api/v1/workspaces/{$this->fiets->public_id}/notifications")->json('meta.total'));
        $this->assertNotNull($offer);
    }

    public function test_overview_counts_paid_orders_only_and_reports_banner_metrics_as_not_available(): void
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/workspaces/{$this->woon->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $connection = WebshopConnection::where('webshop_id', $this->woon->id)->sole();
        $base = ['webshop_id' => $this->woon->id, 'connection_id' => $connection->id, 'platform' => 'prestashop', 'ingestion_status' => 'complete', 'currency' => 'EUR', 'placed_at' => now()->subDay()];
        WebshopOrder::create($base + ['external_order_id' => 'A', 'reference' => 'A', 'amount_minor' => 10000, 'refunded_minor' => 2000, 'payment_status' => 'partially_refunded', 'paid_at' => now()->subDay()]);
        WebshopOrder::create($base + ['external_order_id' => 'B', 'reference' => 'B', 'amount_minor' => 5000, 'refunded_minor' => 0, 'payment_status' => 'pending']);

        Sanctum::actingAs($this->owner);
        $data = $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/analytics/overview")->assertOk()->json('data');
        $this->assertSame(['paid_orders' => 1, 'gross_minor' => 10000, 'refunded_minor' => 2000, 'net_minor' => 8000, 'average_order_minor' => 10000], $data['orders']['EUR']);
        $this->assertSame('no_banners', $data['banner_metrics']['status']); // filled from B14 rollups once banners exist
    }

    public function test_owner_dashboard_combines_only_owned_webshops(): void
    {
        $this->offer($this->woon, 4);
        $this->offer($this->fiets, 6);
        $managed = $this->activeWebshop(User::factory()->create(), ['name' => 'Beheerd']);
        $managed->memberships()->create(['user_id' => $this->owner->id, 'role' => MembershipRole::Manager, 'status' => 'active', 'joined_at' => now()]);
        $this->offer($managed, 9);

        Sanctum::actingAs($this->owner);
        $data = $this->getJson('/api/v1/owner/dashboard')->assertOk()->json('data');
        $this->assertSame(['Fietsen Utrecht', 'Woon & Co'], array_column($data['webshops'], 'name'));
        $this->assertSame(10, $data['totals']['available_codes']);
        $this->assertSame(2, $data['totals']['active_offers']);

        Sanctum::actingAs(User::factory()->create());
        $this->assertSame([], $this->getJson('/api/v1/owner/dashboard')->json('data.webshops'));
    }

    public function test_employees_without_dashboard_permission_are_refused(): void
    {
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/workspaces/{$this->woon->public_id}/dashboard")->assertNotFound();
    }

    public function test_effective_lifecycle_filters_and_pagination_reconcile(): void
    {
        $active = $this->offer($this->woon, 2);
        $expired = $this->offer($this->woon, 2, terms: ['ends_at' => now()->addDay()]);
        $this->travelTo(CarbonImmutable::now()->addDays(2));
        $future = $this->offer($this->woon, 2, terms: ['starts_at' => now()->addDay()]);
        $this->offer($this->woon, 0);
        Sanctum::actingAs($this->owner);
        $base = '/api/v1/workspaces/'.$this->woon->public_id.'/dashboard';
        $this->getJson($base.'?status=active')->assertOk()->assertJsonPath('data.kpis.active_offers', 1)
            ->assertJsonPath('data.offers_meta.total', 1)->assertJsonPath('data.offers.0.id', $active->public_id);
        $this->getJson($base.'?status=expired')->assertOk()->assertJsonPath('data.offers.0.id', $expired->public_id);
        $this->getJson($base.'?status=scheduled')->assertOk()->assertJsonPath('data.offers.0.id', $future->public_id);
        $this->getJson($base.'?status=sold_out')->assertOk()->assertJsonCount(1, 'data.offers');
        $one = $this->getJson($base.'?per_page=2&page=1')->assertOk()->assertJsonPath('data.offers_meta.last_page', 2);
        $two = $this->getJson($base.'?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data.offers');
        $this->assertEmpty(array_intersect(array_column($one->json('data.offers'), 'id'), array_column($two->json('data.offers'), 'id')));
    }

    public function test_claim_drilldown_uses_local_claim_day_even_when_issued_on_another_day(): void
    {
        $offer = $this->offer($this->woon, 4);
        $inventory = app(VoucherInventory::class);
        foreach (['2026-10-24 22:00:00', '2026-10-25 22:59:59', '2026-10-25 23:00:00'] as $i => $stamp) {
            $v = $inventory->issue($inventory->reserve($offer, VoucherOrigin::Answer24Claim, 'dst-'.$i), User::factory()->create());
            VoucherClaim::create(['webshop_id' => $this->woon->id, 'voucher_id' => $v->id, 'token_hash' => hash('sha256', 'dst-'.$i), 'token' => 'test',
                'status' => 'claimed', 'expires_at' => now()->addDays(90), 'claimed_at' => CarbonImmutable::parse($stamp, 'UTC')]);
        }
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/vouchers?claimed=1&from=2026-10-25&to=2026-10-25')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/analytics/claims?from=2026-10-25&to=2026-10-25')
            ->assertOk()->assertJsonPath('data.totals.claims', 2);
        $this->getJson('/api/v1/workspaces/'.$this->fiets->public_id.'/vouchers?claimed=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/workspaces/'.$this->woon->public_id.'/vouchers?claimed=maybe')->assertUnprocessable();
    }

    public function test_resolved_stock_alert_disappears_from_central_inbox_too(): void
    {
        $offer = $this->offer($this->woon, 1);
        $generator = app(MerchantNotifications::class);
        $generator->forWebshop($this->woon, CarbonImmutable::now());
        app(NotificationDispatcher::class)->drain();
        $inbox = app(NotificationInbox::class);
        $this->assertSame(1, $inbox->visible($this->owner)->whereNotNull('legacy_notification_id')->count());
        config(['shopbonus.notifications.low_inventory_threshold' => 1]);
        $generator->forWebshop($this->woon, CarbonImmutable::now());
        $this->assertSame(0, $inbox->visible($this->owner)->whereNotNull('legacy_notification_id')->count());
        $this->assertSame(0, MerchantNotification::where('webshop_id', $this->woon->id)->count());
        $this->assertSame(1, MerchantNotification::withoutGlobalScope('unresolved')->where('webshop_id', $this->woon->id)->count());
    }

    public function test_daily_aggregates_return_bounded_rows_for_ten_thousand_claims(): void
    {
        $offer = $this->offer($this->woon, 1);
        $v = app(VoucherInventory::class)->reserve($offer, VoucherOrigin::Answer24Claim, 'volume');
        // Representative inventory as well as claim volume; use bulk inserts so
        // fixture creation does not benchmark encryption/model events.
        $codeTemplate = VoucherCode::where('offer_id', $offer->id)->firstOrFail()->getAttributes();
        unset($codeTemplate['id']);
        for ($batch = 0; $batch < 100; $batch++) {
            $codes = [];
            for ($i = 0; $i < 100; $i++) {
                $raw = 'VOLUME-CODE-'.($batch * 100 + $i);
                $codes[] = [...$codeTemplate, 'public_id' => (string) Str::ulid(), 'code' => Crypt::encryptString($raw),
                    'code_hash' => VoucherCode::hashFor($this->woon->id, $raw), 'code_last4' => substr($raw, -4), 'status' => 'available'];
            }
            DB::table('voucher_codes')->insert($codes);
        }
        $template = $v->getAttributes();
        unset($template['id']);
        $db = DB::class;
        for ($batch = 0; $batch < 100; $batch++) {
            $vouchers = [];
            for ($i = 0; $i < 100; $i++) {
                $vouchers[] = [...$template, 'public_id' => (string) Str::ulid(), 'idempotency_key' => 'volume-'.($batch * 100 + $i), 'issued_at' => now()->toDateTimeString(), 'status' => 'issued'];
            }
            $db::table('vouchers')->insert($vouchers);
            $ids = $db::table('vouchers')->whereIn('public_id', array_column($vouchers, 'public_id'))->pluck('id');
            $claims = [];
            foreach ($ids as $id) {
                $claims[] = ['public_id' => (string) Str::ulid(), 'webshop_id' => $this->woon->id, 'voucher_id' => $id,
                    'token_hash' => hash('sha256', 'volume-'.$id), 'token' => 'fixture', 'status' => 'claimed', 'expires_at' => now()->addDays(90), 'claimed_at' => now()];
            }
            $db::table('voucher_claims')->insert($claims);
        }
        $db::enableQueryLog();
        $started = microtime(true);
        $series = app(MerchantAnalytics::class)->lifecycleSeries($this->woon->id, CarbonImmutable::now()->subDays(29), CarbonImmutable::now());
        $ms = (microtime(true) - $started) * 1000;
        $queries = $db::getQueryLog();
        $db::disableQueryLog();
        $this->assertSame(10000, $series['totals']['claims']);
        $this->assertSame(10000, $series['totals']['issued']);
        $this->assertCount(30, $series['days']);
        $this->assertCount(3, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('COUNT(*)', $query['query']);
        }
        file_put_contents(storage_path('logs/merchant-overview-benchmark.json'), json_encode(['available_codes' => 10000, 'claims' => 10000, 'issued' => 10000, 'days' => 30, 'aggregate_queries' => 3, 'elapsed_ms' => round($ms, 2), 'database' => $db::connection()->getDriverName()], JSON_PRETTY_PRINT));
    }

    public function test_invalid_batches_and_provider_verification_failures_are_deduplicated(): void
    {
        $offer = $this->offer($this->woon, 25);
        $code = VoucherCode::where('offer_id', $offer->id)->firstOrFail();
        $code->forceFill(['platform_validation' => 'mismatch'])->save();
        VoucherCodeBatch::create(['webshop_id' => $this->woon->id, 'offer_id' => $offer->id, 'source' => 'csv', 'status' => 'preview',
            'totals' => ['submitted' => 2, 'valid' => 1, 'invalid' => 1], 'expires_at' => now()->addHour(), 'created_by' => $this->owner->id]);
        $generator = app(MerchantNotifications::class);
        $this->assertSame(2, $generator->forWebshop($this->woon, CarbonImmutable::now()));
        $this->assertSame(0, $generator->forWebshop($this->woon, CarbonImmutable::now()));
        $this->assertSame(['invalid_batch', 'offer_verification'], MerchantNotification::where('webshop_id', $this->woon->id)->orderBy('type')->pluck('type')->all());
        $this->assertSame(0, MerchantNotification::where('webshop_id', $this->fiets->id)->count());
        $code->forceFill(['platform_validation' => 'verified'])->save();
        $generator->forWebshop($this->woon, CarbonImmutable::now());
        $this->assertSame(0, MerchantNotification::where('webshop_id', $this->woon->id)->where('type', 'offer_verification')->count());
    }
}
