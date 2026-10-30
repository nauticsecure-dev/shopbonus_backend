<?php

namespace Tests\Feature\Offers;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\VoucherOrigin;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * Exactly 10 acceptance scenarios for Trello #17 merchant offer, voucher and dashboard gaps.
 */
class WebshopDashboardOffersE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['first_name' => 'Sanne']);
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co', 'verified_domain' => 'woonenco.nl']);
        Sanctum::actingAs($this->owner);
    }

    private function url(string $suffix, ?Webshop $shop = null): string
    {
        return '/api/v1/workspaces/'.($shop ?? $this->shop)->public_id.$suffix;
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeOffer(array $overrides = []): string
    {
        return $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, ...$overrides])->assertCreated()->json('data.id');
    }

    /** @param  list<string>  $codes */
    private function seedCodes(string $offerId, array $codes): void
    {
        $batch = $this->postJson($this->url("/offers/{$offerId}/code-imports"), ['codes' => implode("\n", $codes)])
            ->assertCreated()->json('data.id');
        $this->postJson($this->url("/code-imports/{$batch}/commit"))->assertOk();
    }

    /** E2E 1 — Publish schedules a future start and activates an offer that can go live now. */
    public function test_e2e1_publish_schedules_future_offers_and_activates_current_ones(): void
    {
        $later = $this->makeOffer(['name' => 'Later', 'starts_at' => now()->addDays(3)->toIso8601String()]);
        $this->postJson($this->url("/offers/{$later}/publish"))->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.lifecycle_status', 'scheduled')
            ->assertJsonPath('data.activated_at', null);
        $actions = $this->getJson($this->url("/offers/{$later}"))->json('data.allowed_actions');
        $this->assertContains('activate', $actions);
        $this->assertContains('pause', $actions);
        $this->assertNotContains('resume', $actions);

        $now = $this->makeOffer(['name' => 'Now']);
        $this->postJson($this->url("/offers/{$now}/publish"))->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.lifecycle_status', 'sold_out');
        $this->assertNotNull(Offer::where('public_id', $now)->firstOrFail()->activated_at);

        $this->postJson($this->url("/offers/{$now}/publish"))->assertStatus(409)->assertJsonPath('error.code', 'invalid_status_transition');
        $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, 'name' => 'Thin', 'max_discount_minor' => null])
            ->assertCreated();
        $thin = Offer::where('webshop_id', $this->shop->id)->whereHas('currentVersion', fn ($q) => $q->where('name', 'Thin'))->firstOrFail();
        $this->postJson($this->url("/offers/{$thin->public_id}/publish"))->assertStatus(422)->assertJsonPath('error.code', 'offer_incomplete');
    }

    /** E2E 2 — The scheduler opens due offers and ends active or paused offers past their end. */
    public function test_e2e2_lifecycle_command_activates_due_offers_and_ends_expired_ones(): void
    {
        $start = now()->addHour()->toIso8601String();
        $end = now()->addHour()->toIso8601String();
        $scheduled = $this->makeOffer(['name' => 'Opens soon', 'starts_at' => $start]);
        $this->postJson($this->url("/offers/{$scheduled}/publish"))->assertOk()->assertJsonPath('data.status', 'scheduled');

        $expiring = $this->makeOffer(['name' => 'Closes soon', 'ends_at' => $end]);
        $this->postJson($this->url("/offers/{$expiring}/publish"))->assertOk()->assertJsonPath('data.status', 'active');

        $paused = $this->makeOffer(['name' => 'Paused window', 'ends_at' => $end]);
        $this->postJson($this->url("/offers/{$paused}/publish"))->assertOk();
        $this->postJson($this->url("/offers/{$paused}/pause"))->assertOk()->assertJsonPath('data.status', 'paused');

        $this->travelTo(now()->addHours(2));
        $this->artisan('shopbonus:advance-offer-lifecycle')->assertSuccessful();
        $this->travelBack();

        $this->assertSame('active', Offer::where('public_id', $scheduled)->firstOrFail()->status->value);
        $this->getJson($this->url("/offers/{$expiring}"))->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.lifecycle_status', 'expired');
        $this->assertSame('ended', Offer::where('public_id', $paused)->firstOrFail()->status->value);
        $this->assertSame('expired', Offer::where('public_id', $paused)->firstOrFail()->status->publicValue());
    }

    /** E2E 3 — Pause, resume and archive follow the status machine and lock edits. */
    public function test_e2e3_pause_resume_and_archive_follow_the_status_machine(): void
    {
        $id = $this->makeOffer(['name' => 'Seasonal']);
        $this->postJson($this->url("/offers/{$id}/publish"))->assertOk();
        $this->postJson($this->url("/offers/{$id}/pause"))->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.lifecycle_status', 'paused');
        $this->assertNotNull(Offer::where('public_id', $id)->firstOrFail()->paused_at);

        $this->postJson($this->url("/offers/{$id}/resume"))->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.paused_at', null);
        $this->assertContains('pause', $this->getJson($this->url("/offers/{$id}"))->json('data.allowed_actions'));

        $this->postJson($this->url("/offers/{$id}/archive"))->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.lifecycle_status', 'archived');
        $this->assertNotNull(Offer::where('public_id', $id)->firstOrFail()->archived_at);
        $this->assertSame(['duplicate'], $this->getJson($this->url("/offers/{$id}"))->json('data.allowed_actions'));
        $this->patchJson($this->url("/offers/{$id}"), ['name' => 'Renamed'])->assertStatus(409)->assertJsonPath('error.code', 'offer_archived');
        $this->postJson($this->url("/offers/{$id}/activate"))->assertStatus(409);

        $ended = $this->makeOffer(['name' => 'Finished']);
        $this->postJson($this->url("/offers/{$ended}/publish"))->assertOk();
        $this->postJson($this->url("/offers/{$ended}/end"))->assertOk()->assertJsonPath('data.lifecycle_status', 'expired');
        $this->postJson($this->url("/offers/{$ended}/archive"))->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertNotNull(Offer::where('public_id', $ended)->firstOrFail()->ended_at);
    }

    /** E2E 4 — Duplicate copies terms into a new draft and does not copy codes. */
    public function test_e2e4_duplicate_copies_terms_into_a_draft_without_codes(): void
    {
        $id = $this->makeOffer(['name' => 'Original deal']);
        $this->seedCodes($id, ['ORIG-1001', 'ORIG-1002']);

        $copyId = $this->postJson($this->url("/offers/{$id}/duplicate"))->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lifecycle_status', 'draft')
            ->assertJsonPath('data.terms.name', 'Original deal (copy)')
            ->assertJsonPath('data.terms.discount_value', 1000)
            ->assertJsonPath('data.terms.min_order_minor', 10000)
            ->assertJsonPath('data.terms.categories', ['wonen'])
            ->assertJsonPath('data.inventory.total', 0)
            ->json('data.id');

        $this->assertNotSame($id, $copyId);
        $this->assertSame(2, $this->getJson($this->url("/offers/{$id}/codes"))->json('meta.counters.total'));
        $this->assertSame(1, AuditEvent::where('webshop_id', $this->shop->id)->where('action', 'offer.duplicated')->count());
        $this->assertSame($id, AuditEvent::where('action', 'offer.duplicated')->firstOrFail()->metadata['source_offer_id']);
    }

    /** E2E 5 — Offer index paginates and filters by storage status and lifecycle. */
    public function test_e2e5_offer_index_paginates_and_filters_lifecycle(): void
    {
        $draft = $this->makeOffer(['name' => 'Still drafting']);
        $scheduled = $this->makeOffer(['name' => 'Next week', 'starts_at' => now()->addWeek()->toIso8601String()]);
        $this->postJson($this->url("/offers/{$scheduled}/publish"))->assertOk();
        $soldOut = $this->makeOffer(['name' => 'No stock']);
        $this->postJson($this->url("/offers/{$soldOut}/publish"))->assertOk();
        $live = $this->makeOffer(['name' => 'In stock']);
        $this->seedCodes($live, ['LIVE-4242']);
        $this->postJson($this->url("/offers/{$live}/publish"))->assertOk()->assertJsonPath('data.lifecycle_status', 'active');

        $page = $this->getJson($this->url('/offers?per_page=1&page=1'))->assertOk();
        $page->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 4)->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.last_page', 4);

        $this->assertSame([$draft], array_column($this->getJson($this->url('/offers?lifecycle=draft'))->json('data'), 'id'));
        $this->assertSame([$scheduled], array_column($this->getJson($this->url('/offers?lifecycle=scheduled'))->json('data'), 'id'));
        $this->assertSame([$soldOut], array_column($this->getJson($this->url('/offers?lifecycle=sold_out'))->json('data'), 'id'));
        $this->assertSame([$live], array_column($this->getJson($this->url('/offers?lifecycle=active&status=active'))->json('data'), 'id'));
        $this->assertSame(['In stock'], array_column(array_column($this->getJson($this->url('/offers?'.http_build_query(['search' => 'In stock'])))->json('data'), 'terms'), 'name'));
    }

    /** E2E 6 — Import template, free shipping, and an end date that does not require a start. */
    public function test_e2e6_import_template_free_shipping_and_date_window(): void
    {
        $id = $this->makeOffer();
        $csv = $this->get($this->url("/offers/{$id}/codes/import-template"))->assertOk()->streamedContent();
        $this->assertStringContainsString('code', $csv);
        $this->assertStringContainsString('SHOP-CODE-0001', $csv);

        $this->postJson($this->url('/offers'), [
            'acquisition' => 'merchant_reward',
            'name' => 'Free shipping over nothing',
            'discount_type' => 'free_shipping',
            'ends_at' => now()->addMonth()->toIso8601String(),
            'redemption_validity_days' => 30,
        ])->assertCreated()
            ->assertJsonPath('data.terms.discount_type', 'free_shipping')
            ->assertJsonPath('data.terms.discount_value', 0)
            ->assertJsonPath('data.terms.summary', 'Free shipping')
            ->assertJsonPath('data.cost_preview.basis', 'free_shipping');

        $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, 'starts_at' => '2026-12-01', 'ends_at' => '2026-11-01'])->assertStatus(422);

        $window = $this->makeOffer([
            'name' => 'Window',
            'starts_at' => now()->addDays(10)->toIso8601String(),
            'ends_at' => now()->addDays(20)->toIso8601String(),
        ]);
        $this->patchJson($this->url("/offers/{$window}"), ['ends_at' => now()->addDays(5)->toIso8601String()])->assertStatus(422);
    }

    /** E2E 7 — Voucher list, detail and export show a masked code and no consumer identity. */
    public function test_e2e7_voucher_list_show_and_export_mask_codes_without_pii(): void
    {
        $id = $this->makeOffer(['name' => 'Issued deal']);
        $this->seedCodes($id, ['SECRET-7781']);
        $this->postJson($this->url("/offers/{$id}/publish"))->assertOk();
        $consumer = User::factory()->create(['email' => 'pii-secret@example.test', 'email_verified_at' => now()]);
        $voucher = app(VoucherInventory::class)->issue(
            app(VoucherInventory::class)->reserve(Offer::where('public_id', $id)->firstOrFail()->fresh(), VoucherOrigin::MerchantReward, 'e2e:issued'),
            $consumer,
        );

        $list = $this->getJson($this->url('/vouchers?status=issued&search=7781'))->assertOk();
        $list->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $voucher->public_id)
            ->assertJsonPath('data.0.code', '****7781')
            ->assertJsonPath('data.0.offer_id', $id);
        $this->assertArrayNotHasKey('consumer', $list->json('data.0'));
        $this->assertStringNotContainsString('pii-secret@example.test', $list->getContent());
        $this->assertStringNotContainsString('SECRET-7781', $list->getContent());
        $this->getJson($this->url('/vouchers?status=redeemed'))->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson($this->url("/vouchers/{$voucher->public_id}"))->assertOk()
            ->assertJsonPath('data.code', '****7781')
            ->assertJsonPath('data.terms.discount_value', 1000)
            ->assertJsonMissingPath('data.consumer');

        $csv = $this->get($this->url('/vouchers/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('****7781', $csv);
        $this->assertStringNotContainsString('SECRET-7781', $csv);
        $this->assertStringNotContainsString('pii-secret@example.test', $csv);

        $other = User::factory()->create();
        $otherShop = $this->activeWebshop($other, ['name' => 'Other']);
        Sanctum::actingAs($other);
        $this->getJson($this->url("/vouchers/{$voucher->public_id}", $otherShop))->assertNotFound();
        $this->assertSame(0, $this->getJson($this->url('/vouchers', $otherShop))->json('meta.total'));
    }

    /** E2E 8 — A merchant can open one dispute on its own voucher. */
    public function test_e2e8_merchant_can_open_a_dispute_on_its_voucher(): void
    {
        $id = $this->makeOffer(['name' => 'Disputed']);
        $this->seedCodes($id, ['DISP-3390']);
        $this->postJson($this->url("/offers/{$id}/publish"))->assertOk();
        $voucher = app(VoucherInventory::class)->issue(
            app(VoucherInventory::class)->reserve(Offer::where('public_id', $id)->firstOrFail()->fresh(), VoucherOrigin::MerchantReward, 'e2e:dispute'),
            User::factory()->create(['email_verified_at' => now()]),
        );

        $this->postJson($this->url("/vouchers/{$voucher->public_id}/dispute"), [
            'reason' => 'code_invalid',
            'description' => 'The customer was refused at checkout with this code.',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.reason', 'code_invalid');

        $this->postJson($this->url("/vouchers/{$voucher->public_id}/dispute"), [
            'reason' => 'other',
            'description' => 'A second report must not open while the first is open.',
        ])->assertStatus(409)->assertJsonPath('error.code', 'dispute_already_open');

        $this->assertSame(1, $this->getJson($this->url('/dashboard'))->json('data.kpis.open_disputes'));
    }

    /** E2E 9 — Dashboard adds health, activity, quick actions and an empty flag. */
    public function test_e2e9_dashboard_reports_kpis_health_activity_actions_and_empty(): void
    {
        $empty = $this->getJson($this->url('/dashboard'))->assertOk();
        $empty->assertJsonPath('data.empty', true)
            ->assertJsonPath('data.connection_health.status', 'none')
            ->assertJsonPath('data.connection_health.total', 0)
            ->assertJsonPath('data.kpis.active_offers', 0)
            ->assertJsonPath('data.kpis.scheduled_offers', 0)
            ->assertJsonPath('data.quick_actions.0.key', 'create_offer')
            ->assertJsonPath('data.quick_actions.1.available', false);
        $this->assertIsArray($empty->json('data.recent_activity'));

        $this->makeOffer(['name' => 'First offer']);
        $this->postJson($this->url('/connections'), ['platform' => 'prestashop'])->assertCreated();
        $filled = $this->getJson($this->url('/dashboard'))->assertOk();
        $filled->assertJsonPath('data.empty', false)
            ->assertJsonPath('data.connection_health.total', 1)
            ->assertJsonPath('data.connection_health.status', 'setup_required')
            ->assertJsonPath('data.quick_actions.1.key', 'import_codes')
            ->assertJsonPath('data.quick_actions.1.available', true);
        $activity = $filled->json('data.recent_activity');
        $this->assertContains('offer.created', array_column($activity, 'action'));
        $this->assertSame(['action', 'at', 'resource_type'], array_keys($activity[0]));
        $this->assertStringNotContainsString($this->owner->email, $filled->getContent());
    }

    /** E2E 10 — New offer and voucher routes stay inside the workspace and its permissions. */
    public function test_e2e10_archived_and_foreign_offers_stay_isolated(): void
    {
        $id = $this->makeOffer(['name' => 'Private']);
        $other = User::factory()->create();
        $otherShop = $this->activeWebshop($other, ['name' => 'Elsewhere']);
        Sanctum::actingAs($other);
        $this->getJson($this->url("/offers/{$id}", $otherShop))->assertNotFound();
        $this->postJson($this->url("/offers/{$id}/publish", $otherShop))->assertNotFound();
        $this->postJson($this->url("/offers/{$id}/duplicate", $otherShop))->assertNotFound();
        $this->postJson($this->url("/offers/{$id}/archive", $otherShop))->assertNotFound();
        $this->assertSame(0, Offer::where('webshop_id', $otherShop->id)->count());

        $employee = User::factory()->create();
        $this->shop->memberships()->create([
            'user_id' => $employee->id,
            'role' => MembershipRole::Employee,
            'permissions' => ['vouchers.view'],
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($employee);
        $this->getJson($this->url('/vouchers'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->get($this->url('/vouchers/export'))->assertForbidden();
        $this->postJson($this->url("/offers/{$id}/publish"))->assertForbidden();
        $this->assertSame('draft', Offer::where('public_id', $id)->firstOrFail()->status->value);
    }
}
