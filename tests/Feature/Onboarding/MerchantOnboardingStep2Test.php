<?php

namespace Tests\Feature\Onboarding;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OfferStatus;
use App\Enums\VoucherCodeStatus;
use App\Models\AuditEvent;
use App\Models\BusinessProfile;
use App\Models\Offer;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\VoucherCodeBatch;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Funding\MerchantFunding;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Concerns\CreatesMinimalXlsx;
use Tests\TestCase;

/**
 * Exactly 10 end-to-end tests for merchant onboarding Step 2 (Trello #9).
 */
class MerchantOnboardingStep2Test extends TestCase
{
    use CreatesActiveWebshops, CreatesMinimalXlsx, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private const TEN_PERCENT = [
        'acquisition' => 'merchant_reward',
        'name' => 'Woon & Co: 10% volgende aankoop',
        'public_description' => 'Niet combineerbaar met andere acties.',
        'discount_type' => 'percentage',
        'discount_value' => 1000,
        'min_order_minor' => 10000,
        'max_discount_minor' => 1000,
        'claim_validity_days' => 90,
        'redemption_validity_days' => 90,
        'combinable' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['onboarding_intent' => 'merchant']);
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co', 'verified_domain' => 'woonenco.nl']);
        $this->seedDetails();
        Sanctum::actingAs($this->owner);
    }

    private function url(string $suffix = '', ?Webshop $shop = null): string
    {
        return '/api/v1/workspaces/'.($shop ?? $this->shop)->public_id.$suffix;
    }

    private function seedDetails(): void
    {
        $profile = BusinessProfile::query()->where('webshop_id', $this->shop->id)->first() ?? new BusinessProfile;
        $profile->webshop_id = $this->shop->id;
        $profile->forceFill([
            'legal_name' => 'Woon & Co B.V.',
            'trading_name' => 'Woon & Co',
            'website_url' => 'https://woonenco.nl',
            'website_domain' => 'woonenco.nl',
            'kvk_number' => '12345678',
            'vat_number' => 'NL001234567B01',
            'contact_email' => 'hello@woonenco.nl',
            'contact_phone' => '+31612345678',
            'main_contact_name' => 'Pieter',
            'address_line' => 'Voorstraat 1',
            'postal_code' => '1234AB',
            'city' => 'Utrecht',
            'country_code' => 'NL',
            'preferred_language' => 'nl',
            'business_category' => 'home living',
            'source' => 'manual',
            'confirmed_at' => now(),
            'confirmed_by' => $this->owner->id,
        ])->save();

        WebshopPublicProfile::query()->updateOrCreate(
            ['webshop_id' => $this->shop->id],
            [
                'display_name' => 'Woon & Co',
                'short_description' => 'Alles voor een stijlvol thuis',
                'description' => 'Alles voor een stijlvol thuis. Van meubels en decoratie tot verlichting en woonaccessoires.',
                'website_url' => 'https://woonenco.nl',
                'slug' => 'woon-co-'.substr($this->shop->public_id, -6),
                'published' => false,
            ]
        );
        DB::table('webshop_public_categories')->where('webshop_id', $this->shop->id)->delete();
        DB::table('webshop_public_categories')->insert(['webshop_id' => $this->shop->id, 'category' => 'home_living']);
    }

    /** E2E 1 — Onboarding progress */
    public function test_e2e1_onboarding_progress_stepper_and_step1_edits_persist(): void
    {
        $before = $this->getJson($this->url('/onboarding'))->assertOk();
        $reg = collect($before->json('data.registration.steps'))->keyBy('key');
        $this->assertSame('complete', $reg['details']['status']);
        $this->assertContains($reg['voucher_setup']['status'], ['current', 'not_started']);
        $this->assertSame('pending', $reg['activate']['status']);
        $this->assertSame('voucher_setup', $before->json('data.registration.current_step'));

        $this->putJson($this->url('/public-profile'), [
            'display_name' => 'Woon & Co Atelier',
            'short_description' => 'Alles voor een stijlvol thuis',
            'categories' => ['home_living', 'garden_diy'],
        ])->assertOk();

        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->assertCreated()->json('data.id');
        $previewId = $this->postJson($this->url("/offers/{$id}/code-imports"), ['codes' => "WC-A001\nWC-A002"])->json('data.id');
        $this->postJson($this->url("/code-imports/{$previewId}/commit"))->assertOk();

        $again = $this->getJson($this->url('/onboarding'))->assertOk();
        $this->assertSame('Woon & Co Atelier', $this->getJson($this->url('/public-profile'))->json('data.display_name'));
        $this->assertSame($id, $again->json('data.registration.first_offer_id'));
        $this->assertSame('home_living', $again->json('data.registration.branch_defaults.category'));

        $steps = collect($again->json('data.registration.steps'))->keyBy('key');
        $this->assertSame('complete', $steps['details']['status']);
        $this->assertSame('complete', $steps['voucher_setup']['status']);
    }

    /** E2E 2 — Percentage offer persistence */
    public function test_e2e2_percentage_offer_persists_after_reload(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->assertCreated()->json('data.id');

        $reloaded = $this->getJson($this->url("/offers/{$id}"))->assertOk();
        foreach ([
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'min_order_minor' => 10000,
            'max_discount_minor' => 1000,
            'claim_validity_days' => 90,
            'redemption_validity_days' => 90,
            'public_description' => 'Niet combineerbaar met andere acties.',
        ] as $field => $value) {
            $reloaded->assertJsonPath("data.terms.{$field}", $value);
        }
        $this->assertSame('draft', $reloaded->json('data.status'));
        $this->assertNotEmpty($reloaded->json('data.preview.journey.headline'));
    }

    /** E2E 3 — Fixed-value offer */
    public function test_e2e3_fixed_value_offer_validation_and_model_update(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');

        $this->patchJson($this->url("/offers/{$id}"), [
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'min_order_minor' => 5000,
            'max_discount_minor' => null,
            'name' => 'Woon & Co: €5 voucher',
        ])->assertOk()
            ->assertJsonPath('data.terms.discount_type', 'fixed')
            ->assertJsonPath('data.terms.discount_value', 500)
            ->assertJsonPath('data.terms.min_order_minor', 5000)
            ->assertJsonPath('data.cost_preview.basis', 'fixed_value')
            ->assertJsonPath('data.cost_preview.per_voucher.reward_minor', 500);

        $this->patchJson($this->url("/offers/{$id}"), [
            'discount_type' => 'percentage',
            'discount_value' => 15000,
        ])->assertStatus(422);

        $this->assertSame('fixed', Offer::where('public_id', $id)->first()->currentVersion->discount_type);
    }

    /** E2E 4 — Live preview accuracy */
    public function test_e2e4_live_preview_next_purchase_copy_and_unpublished(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->assertCreated()->json('data.id');
        $preview = $this->getJson($this->url("/offers/{$id}"))->json('data.preview');

        $this->assertFalse($preview['published']);
        $this->assertSame('earn_with_purchase', $preview['how_to_obtain']);
        $this->assertSame('Woon & Co', $preview['webshop']['name']);
        $this->assertStringContainsString('next eligible purchase', $preview['journey']['headline']);
        $this->assertStringContainsString('€100.00', $preview['journey']['headline']);
        $this->assertStringContainsString('€10.00', $preview['journey']['headline']);
        $this->assertStringNotContainsString('today', mb_strtolower($preview['journey']['headline']));
        $this->assertSame('Customer has 90 days to claim an issued voucher.', $preview['journey']['claim_window']);
        $this->assertSame('A claimed voucher remains usable for 90 days.', $preview['journey']['redemption_window']);

        $this->patchJson($this->url("/offers/{$id}"), ['min_order_minor' => 15000, 'max_discount_minor' => 1200])->assertOk();
        $updated = $this->getJson($this->url("/offers/{$id}"))->json('data.preview');
        $this->assertStringContainsString('€150.00', $updated['journey']['headline']);
        $this->assertStringContainsString('€12.00', $updated['journey']['headline']);

        $slugs = collect($this->getJson('/api/v1/public/offers')->assertOk()->json('data'))->pluck('slug');
        $this->assertFalse($slugs->contains(Offer::where('public_id', $id)->value('slug')));
    }

    /** E2E 5 — Merchant cost calculation */
    public function test_e2e5_merchant_cost_preview_uses_fee_calculator_without_charge(): void
    {
        $wallet = app(MerchantFunding::class)->wallet($this->shop);
        $walletBefore = $wallet->fresh()->available;

        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->assertCreated()
            ->assertJsonPath('data.cost_preview.per_voucher', [
                'reward_minor' => 1000,
                'fee_minor' => 300,
                'merchant_commitment_minor' => 1300,
            ])
            ->assertJsonPath('data.cost_preview.fee_rate_bps', 3000)
            ->json('data.id');

        $this->patchJson($this->url("/offers/{$id}"), ['max_discount_minor' => 2000])->assertOk()
            ->assertJsonPath('data.cost_preview.per_voucher.reward_minor', 1000)
            ->assertJsonPath('data.cost_preview.per_voucher.merchant_commitment_minor', 1300);

        $this->patchJson($this->url("/offers/{$id}"), [
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'min_order_minor' => 10000,
        ])->assertOk()->assertJsonPath('data.cost_preview.per_voucher', [
            'reward_minor' => 1000, 'fee_minor' => 300, 'merchant_commitment_minor' => 1300,
        ]);

        $this->assertSame($walletBefore, $wallet->fresh()->available);
        $this->assertStringContainsString('Nothing is charged', $this->getJson($this->url("/offers/{$id}"))->json('data.cost_preview.note'));
    }

    /** E2E 6 — Code paste / CSV / Excel import */
    public function test_e2e6_code_paste_csv_and_excel_import_into_inventory(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');

        $lines = [];
        foreach (range(1, 90) as $i) {
            $lines[] = sprintf('PASTE-%03d', $i);
        }
        $lines[] = 'PASTE-001';
        $lines[] = 'bad code!';
        foreach (range(91, 98) as $i) {
            $lines[] = sprintf('PASTE-%03d', $i);
        }

        $preview = $this->postJson($this->url("/offers/{$id}/code-imports"), ['codes' => implode("\n", $lines)])->assertCreated();
        $this->assertSame(100, $preview->json('data.totals.submitted'));
        $this->assertSame(1, $preview->json('data.totals.duplicate_in_upload'));
        $this->assertSame(1, $preview->json('data.totals.invalid'));
        $this->assertSame(98, $preview->json('data.totals.valid'));
        $batchId = $preview->json('data.id');
        $this->postJson($this->url("/code-imports/{$batchId}/commit"))->assertOk()
            ->assertJsonPath('data.totals.imported', 98);

        $csv = UploadedFile::fake()->createWithContent('codes.csv', "code\nCSV-0001\nCSV-0002\nCSV-0001\n");
        $csvPreview = $this->post($this->url("/offers/{$id}/code-imports"), ['file' => $csv], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.source', 'file')->assertJsonPath('data.totals.valid', 2);
        $this->postJson($this->url('/code-imports/'.$csvPreview->json('data.id').'/commit'))->assertOk();

        $xlsx = $this->xlsxWithCodes(['XLSX-0001', 'XLSX-0002', 'XLSX-0001']);
        $xlsxPreview = $this->post($this->url("/offers/{$id}/code-imports"), ['file' => $xlsx], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.totals.valid', 2)->assertJsonPath('data.totals.duplicate_in_upload', 1);
        $this->postJson($this->url('/code-imports/'.$xlsxPreview->json('data.id').'/commit'))->assertOk();

        $this->assertSame(102, VoucherCode::count());
        $this->assertNotNull(VoucherCodeBatch::where('public_id', $batchId)->value('id'));
    }

    /** E2E 7 — Checkout compatibility */
    public function test_e2e7_imported_codes_are_not_checkout_verified(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $previewId = $this->postJson($this->url("/offers/{$id}/code-imports"), ['codes' => "FAKE-OK-01\nFAKE-OK-02"])->json('data.id');
        $this->postJson($this->url("/code-imports/{$previewId}/commit"))->assertOk();

        $sheet = $this->getJson($this->url("/offers/{$id}/codes"))->assertOk();
        foreach ($sheet->json('data') as $row) {
            $this->assertSame('merchant_verification_required', $row['platform_validation']);
        }

        $reg = $this->getJson($this->url('/onboarding'))->json('data.registration');
        $codes = collect($reg['activation_blockers'])->pluck('code');
        $this->assertTrue($codes->contains('connection_unverified'));
        $this->assertSame(OfferStatus::Draft, Offer::where('public_id', $id)->first()->status);
    }

    /** E2E 8 — Webshop details */
    public function test_e2e8_webshop_details_preview_update_and_domain_reverify(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $this->assertSame('Woon & Co', $this->getJson($this->url("/offers/{$id}"))->json('data.preview.webshop.name'));

        $this->putJson($this->url('/public-profile'), [
            'categories' => ['home_living', 'electronics'],
            'display_name' => 'Woon & Co',
            'short_description' => 'Alles voor een stijlvol thuis',
        ])->assertOk();

        $cats = collect($this->getJson($this->url("/offers/{$id}"))->json('data.preview.categories'))->pluck('key');
        $this->assertTrue($cats->contains('electronics'));

        $defaults = $this->getJson($this->url('/onboarding'))->json('data.registration.branch_defaults');
        $this->assertContains($defaults['category'], ['home_living', 'electronics']);
        $this->assertNotEmpty($defaults['suggestion']['discount_value']);

        $profile = $this->getJson($this->url('/business-profile'))->assertOk();
        $this->assertTrue($profile->json('data.domain_locked'));

        $response = $this->putJson($this->url('/business-profile'), [
            'website_url' => 'https://other-domain.example',
            'confirm' => true,
        ]);
        // Locked domains refuse silent takeover; ownership must be re-verified for a new domain.
        $this->assertTrue(in_array($response->status(), [200, 422, 409], true));
        $after = $this->getJson($this->url('/business-profile'))->json('data');
        if ($response->status() === 200 && ($after['website_domain'] ?? '') !== 'woonenco.nl') {
            $this->assertFalse($this->shop->fresh()->isOwnershipVerified() || ($after['domain_locked'] ?? true));
        } else {
            $this->assertSame('woonenco.nl', $after['website_domain'] ?? $this->shop->fresh()->verified_domain);
        }
    }

    /** E2E 9 — Draft and activation gating */
    public function test_e2e9_draft_saves_and_step3_shows_blockers_without_publishing(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->assertCreated()->json('data.id');
        $previewId = $this->postJson($this->url("/offers/{$id}/code-imports"), ['codes' => "GATE-0001\nGATE-0002"])->json('data.id');
        $this->postJson($this->url("/code-imports/{$previewId}/commit"))->assertOk();

        $wallet = app(MerchantFunding::class)->wallet($this->shop);
        if ($wallet->available > 0) {
            app(WalletService::class)->debit($wallet, $wallet->available, 'test-drain:'.$this->shop->id);
        }

        $reg = $this->getJson($this->url('/onboarding'))->assertOk()->json('data.registration');
        $blockerCodes = collect($reg['activation_blockers'])->pluck('code');
        $this->assertTrue($blockerCodes->contains('funding_insufficient') || $blockerCodes->contains('connection_unverified'));
        $offer = Offer::where('public_id', $id)->first();
        $this->assertSame(OfferStatus::Draft, $offer->status);
        $this->assertNull($offer->activated_at);

        app(WalletService::class)->credit($wallet, 1_000_000, 'test-refill:'.$this->shop->id, neverExpires: true);
        $this->assertSame('draft', $this->getJson($this->url("/offers/{$id}"))->json('data.status'));
        $this->assertTrue(collect($this->getJson($this->url('/onboarding'))->json('data.registration.activation_blockers'))
            ->pluck('code')->contains('connection_unverified'));
    }

    /** E2E 10 — Permissions and recovery */
    public function test_e2e10_permissions_failed_upload_and_safe_retry(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');
        $okPreview = $this->postJson($this->url("/offers/{$id}/code-imports"), ['codes' => "KEEP-0001\nKEEP-0002"])->json('data.id');
        $this->postJson($this->url("/code-imports/{$okPreview}/commit"))->assertOk();
        $this->assertSame(2, VoucherCode::count());

        $other = User::factory()->create();
        $otherShop = $this->activeWebshop($other, ['name' => 'Other Shop']);
        Sanctum::actingAs($other);
        $this->patchJson($this->url("/offers/{$id}", $otherShop), ['name' => 'Hijack'])->assertNotFound();
        $this->patchJson($this->url("/offers/{$id}"), ['name' => 'Hijack'])->assertNotFound();

        Sanctum::actingAs($this->owner);
        $employee = User::factory()->create();
        $this->shop->memberships()->create([
            'user_id' => $employee->id,
            'role' => MembershipRole::Employee,
            'permissions' => ['offers.view'],
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]);
        Sanctum::actingAs($employee);
        $this->patchJson($this->url("/offers/{$id}"), ['name' => 'No permission'])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $bad = UploadedFile::fake()->createWithContent('codes.xlsx', 'not-a-zip');
        $this->post($this->url("/offers/{$id}/code-imports"), ['file' => $bad], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(2, VoucherCode::count());

        $retry = $this->postJson($this->url("/offers/{$id}/code-imports"), ['codes' => "KEEP-0003\nKEEP-0001"])->assertCreated();
        $this->assertSame(1, $retry->json('data.totals.duplicate_existing'));
        $this->assertSame(1, $retry->json('data.totals.valid'));
        $this->postJson($this->url('/code-imports/'.$retry->json('data.id').'/commit'))->assertOk();
        $this->assertSame(3, VoucherCode::count());
        $this->assertSame('Woon & Co: 10% volgende aankoop', Offer::where('public_id', $id)->first()->currentVersion->name);
        $this->assertSame(VoucherCodeStatus::Available, VoucherCode::where('code_last4', '0001')->first()->status);
        $this->assertGreaterThanOrEqual(1, AuditEvent::where('action', 'like', 'codes.import%')->count());
    }
}
