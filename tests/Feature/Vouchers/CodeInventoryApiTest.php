<?php

namespace Tests\Feature\Vouchers;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\VoucherOrigin;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\VoucherCodeBatch;
use App\Models\Webshop;
use App\Services\Offers\OfferService;
use App\Services\Vouchers\VoucherInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B06b code sheet, import and actions (Trello #7 E2E 4, 9, 10).
 */
class CodeInventoryApiTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        $this->offer = $this->offer();
        Sanctum::actingAs($this->owner);
    }

    private function offer(array $terms = [], ?Webshop $shop = null): Offer
    {
        $shop ??= $this->shop;
        $membership = $shop->memberships()->where('role', 'owner')->firstOrFail()->setRelation('webshop', $shop);

        return app(OfferService::class)->create($membership, OfferAcquisition::MerchantReward, [
            'name' => '€10 korting', 'discount_type' => 'fixed', 'discount_value' => 1000, 'redemption_validity_days' => 90, ...$terms,
        ]);
    }

    private function url(string $suffix, ?Webshop $shop = null): string
    {
        return '/api/v1/workspaces/'.($shop ?? $this->shop)->public_id.$suffix;
    }

    private function importAndCommit(array $codes, ?Offer $offer = null): VoucherCodeBatch
    {
        $offer ??= $this->offer;
        $id = $this->postJson($this->url("/offers/{$offer->public_id}/code-imports"), ['codes' => implode("\n", $codes)])->assertCreated()->json('data.id');
        $this->postJson($this->url("/code-imports/{$id}/commit"))->assertOk();

        return VoucherCodeBatch::where('public_id', $id)->sole();
    }

    public function test_e2e4_paste_preview_reports_duplicates_and_invalid_then_commits_exact_totals(): void
    {
        VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $this->offer->id, 'code' => 'EXISTING01',
            'code_hash' => VoucherCode::hashFor($this->shop->id, 'EXISTING01'), 'code_last4' => 'NG01']);

        $paste = "WOON-0001\nWOON-0002\n  WOON-0003  \nwoon-0001\nbad code!\n\nWOON-0004\nWOON-0005\nWOON-0006\nexisting01\nWOON-0007";
        $preview = $this->postJson($this->url("/offers/{$this->offer->public_id}/code-imports"), ['codes' => $paste])->assertCreated()
            ->assertJsonPath('data.status', 'preview')
            ->assertJsonPath('data.totals', ['submitted' => 10, 'valid' => 7, 'invalid' => 1, 'duplicate_in_upload' => 1, 'duplicate_existing' => 1]);

        $reasons = collect($preview->json('data.rejections'))->pluck('reason', 'line')->all();
        $this->assertSame([4 => 'duplicate_in_upload', 5 => 'invalid_format', 9 => 'duplicate_existing'], $reasons);
        $this->assertStringNotContainsString('WOON-0001', json_encode($preview->json('data.rejections'))); // masked
        $this->assertSame(1, VoucherCode::count()); // preview creates nothing

        $this->postJson($this->url('/code-imports/'.$preview->json('data.id').'/commit'))->assertOk()
            ->assertJsonPath('data.status', 'committed')->assertJsonPath('data.totals.imported', 7);
        $this->postJson($this->url('/code-imports/'.$preview->json('data.id').'/commit'))->assertStatus(409)->assertJsonPath('error.code', 'batch_not_committable');

        $this->assertSame(8, VoucherCode::count());
        $this->assertStringNotContainsString('WOON-0003', (string) DB::table('voucher_codes')->where('code_last4', '0003')->value('code')); // encrypted
        $this->assertSame(['codes.import_previewed', 'codes.import_committed'], AuditEvent::where('action', 'like', 'codes.import%')->orderBy('id')->pluck('action')->all());
    }

    public function test_e2e4_csv_upload_with_header_and_xlsx_refused(): void
    {
        $csv = UploadedFile::fake()->createWithContent('codes.csv', "batch,code\nA,CSV-0001\nA,CSV-0002\nA,CSV-0001\n");
        $this->post($this->url("/offers/{$this->offer->public_id}/code-imports"), ['file' => $csv], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.source', 'file')->assertJsonPath('data.totals.valid', 2)->assertJsonPath('data.totals.duplicate_in_upload', 1);

        $xlsx = UploadedFile::fake()->createWithContent('codes.xlsx', 'PK...');
        $this->post($this->url("/offers/{$this->offer->public_id}/code-imports"), ['file' => $xlsx], ['Accept' => 'application/json'])
            ->assertStatus(415)->assertJsonPath('error.code', 'unsupported_media_type');
    }

    public function test_expired_preview_cannot_be_committed(): void
    {
        $id = $this->postJson($this->url("/offers/{$this->offer->public_id}/code-imports"), ['codes' => "EXP-0001\nEXP-0002"])->json('data.id');
        $this->travel(61)->minutes();

        $this->postJson($this->url("/code-imports/{$id}/commit"))->assertStatus(409);
        $this->assertSame(0, VoucherCode::count());
    }

    public function test_codes_added_after_preview_are_skipped_not_duplicated(): void
    {
        $id = $this->postJson($this->url("/offers/{$this->offer->public_id}/code-imports"), ['codes' => "LATE-0001\nLATE-0002"])->json('data.id');
        VoucherCode::create(['webshop_id' => $this->shop->id, 'offer_id' => $this->offer->id, 'code' => 'LATE-0001',
            'code_hash' => VoucherCode::hashFor($this->shop->id, 'LATE-0001'), 'code_last4' => '0001']);

        $this->postJson($this->url("/code-imports/{$id}/commit"))->assertOk()
            ->assertJsonPath('data.totals.imported', 1)->assertJsonPath('data.totals.duplicate_at_commit', 1);
        $this->assertSame(2, VoucherCode::count());
    }

    public function test_e2e9_500_codes_three_batches_filters_counters_and_export(): void
    {
        $b1 = $this->importAndCommit(array_map(fn ($i) => sprintf('B1-%04d', $i), range(1, 200)));
        $b2 = $this->importAndCommit(array_map(fn ($i) => sprintf('B2-%04d', $i), range(1, 200)));
        $this->importAndCommit(array_map(fn ($i) => sprintf('B3-%04d', $i), range(1, 100)));

        app(OfferService::class)->transition($this->offer->fresh(), OfferStatus::Active);
        $inventory = app(VoucherInventory::class);
        foreach (range(1, 3) as $i) {
            $inventory->issue($inventory->reserve($this->offer, VoucherOrigin::MerchantReward, "k{$i}"), User::factory()->create());
        }
        $inventory->reserve($this->offer, VoucherOrigin::Answer24Claim, 'k-reserved');
        $invalidate = VoucherCode::where('status', 'available')->orderByDesc('id')->first();
        $this->postJson($this->url("/codes/{$invalidate->public_id}/invalidate"), ['reason' => 'Not accepted by checkout'])->assertOk();

        $sheet = $this->getJson($this->url("/offers/{$this->offer->public_id}/codes?per_page=50"))->assertOk();
        $this->assertSame(['total' => 500, 'available' => 495, 'reserved' => 1, 'issued' => 3, 'redeemed' => 0, 'expired' => 0, 'invalid' => 1, 'cancelled' => 0],
            $sheet->json('meta.counters'));
        $this->assertSame(500, $sheet->json('meta.total'));
        $this->assertCount(50, $sheet->json('data'));
        $this->assertMatchesRegularExpression('/^\*{4}.{4}$/', $sheet->json('data.0.code'));

        $this->assertSame(200, $this->getJson($this->url("/offers/{$this->offer->public_id}/codes?batch={$b2->public_id}"))->json('meta.total'));
        $this->assertSame(3, $this->getJson($this->url("/offers/{$this->offer->public_id}/codes?status=issued"))->json('meta.total'));
        $this->assertSame(4, $this->getJson($this->url("/offers/{$this->offer->public_id}/codes?assigned=1"))->json('meta.total'));
        $found = $this->getJson($this->url("/offers/{$this->offer->public_id}/codes?search=b1-0150"))->json('data');
        $this->assertCount(1, $found);
        $this->assertSame('****0150', $found[0]['code']);

        $issued = $this->getJson($this->url("/offers/{$this->offer->public_id}/codes?status=issued"))->json('data.0');
        $this->assertNotNull($issued['assigned']['voucher_id']);
        $this->assertMatchesRegularExpression('/^.\*+@/', $issued['assigned']['consumer']);

        $csv = $this->get($this->url("/offers/{$this->offer->public_id}/codes/export?batch={$b1->public_id}"))->assertOk()->streamedContent();
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(201, $lines); // header + 200
        $this->assertStringContainsString('B1-0001', $csv);
        $this->assertStringNotContainsString('B2-0001', $csv);
        $this->assertSame(1, AuditEvent::where('action', 'codes.exported')->count());
    }

    public function test_reveal_is_audited_and_invalidate_only_unused(): void
    {
        $this->importAndCommit(['REVEAL-0001', 'REVEAL-0002']);
        $code = VoucherCode::orderBy('id')->first();

        $this->postJson($this->url("/codes/{$code->public_id}/reveal"))->assertOk()->assertJsonPath('data.code', 'REVEAL-0001');
        $this->assertSame(1, AuditEvent::where('action', 'codes.revealed')->count());

        app(OfferService::class)->transition($this->offer->fresh(), OfferStatus::Active);
        app(VoucherInventory::class)->reserve($this->offer, VoucherOrigin::MerchantReward, 'k1');
        $this->postJson($this->url("/codes/{$code->public_id}/invalidate"), ['reason' => 'no'])->assertStatus(422);
        $this->postJson($this->url("/codes/{$code->public_id}/invalidate"), ['reason' => 'Broken at checkout'])->assertStatus(409)->assertJsonPath('error.code', 'code_not_available');

        $history = $this->getJson($this->url("/codes/{$code->public_id}/history"))->assertOk()->json('data');
        $this->assertSame('reserved', end($history)['type'] ?? null); // reservation is one voucher event carrying the code
    }

    public function test_reusable_offer_accepts_exactly_one_code(): void
    {
        $reusable = $this->offer(['code_mode' => 'reusable']);
        $this->postJson($this->url("/offers/{$reusable->public_id}/code-imports"), ['codes' => "ZOMER10\nWINTER10"])
            ->assertStatus(422)->assertJsonPath('error.code', 'reusable_offer_single_code');
        $this->importAndCommit(['ZOMER10'], $reusable);
        $this->assertTrue(VoucherCode::sole()->reusable);
    }

    public function test_e2e10_other_merchants_cannot_reach_codes_and_employees_cannot_export(): void
    {
        $this->importAndCommit(['PRIV-0001']);
        $code = VoucherCode::sole();

        $other = User::factory()->create();
        $otherShop = $this->activeWebshop($other);
        Sanctum::actingAs($other);
        $this->getJson($this->url("/offers/{$this->offer->public_id}/codes", $otherShop))->assertNotFound();
        $this->postJson($this->url("/codes/{$code->public_id}/reveal", $otherShop))->assertNotFound();
        $this->getJson($this->url("/offers/{$this->offer->public_id}/codes"))->assertNotFound();

        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'permissions' => ['vouchers.view'], 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->getJson($this->url("/offers/{$this->offer->public_id}/codes"))->assertOk();
        $this->postJson($this->url("/codes/{$code->public_id}/reveal"))->assertForbidden();
        $this->get($this->url("/offers/{$this->offer->public_id}/codes/export"), ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_e2e10_failed_upload_creates_nothing(): void
    {
        $this->postJson($this->url("/offers/{$this->offer->public_id}/code-imports"), [])->assertStatus(422);
        $this->postJson($this->url("/offers/{$this->offer->public_id}/code-imports"), ['codes' => str_repeat("X\n", 3)])->assertCreated()
            ->assertJsonPath('data.totals.valid', 0);

        $this->assertSame(0, VoucherCode::count());
    }
}
