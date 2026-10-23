<?php

namespace Tests\Feature\Offers;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\AuditEvent;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Finance\FeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

/**
 * B06a: offers, versioned terms and cost preview (Trello #7).
 */
class OfferTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private const TEN_PERCENT = [
        'acquisition' => 'merchant_reward', 'name' => 'Woon & Co: 10% korting', 'public_description' => 'Op je volgende aankoop',
        'discount_type' => 'percentage', 'discount_value' => 1000, 'min_order_minor' => 10000, 'max_discount_minor' => 1000,
        'redemption_validity_days' => 90, 'categories' => ['wonen'], 'combinable' => false, 'allocation_limit' => 500,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->shop = $this->activeWebshop($this->owner, ['name' => 'Woon & Co']);
        Sanctum::actingAs($this->owner);
    }

    private function url(string $suffix = '', ?Webshop $shop = null): string
    {
        return '/api/v1/workspaces/'.($shop ?? $this->shop)->public_id.$suffix;
    }

    public function test_e2e2_create_ten_percent_offer_with_minimum_maximum_and_validity(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.terms.version', 1)
            ->assertJsonPath('data.terms.locked', false)
            ->assertJsonPath('data.terms.summary', '10% from €100.00, maximum €10.00')
            ->assertJsonPath('data.terms.claim_validity_days', 90)
            ->json('data.id');

        $reopened = $this->getJson($this->url("/offers/{$id}"))->assertOk();
        foreach (['discount_type' => 'percentage', 'discount_value' => 1000, 'min_order_minor' => 10000, 'max_discount_minor' => 1000,
            'redemption_validity_days' => 90, 'categories' => ['wonen'], 'currency' => 'EUR', 'code_mode' => 'unique'] as $field => $value) {
            $reopened->assertJsonPath("data.terms.{$field}", $value);
        }

        // Card #7 §4 cost preview: €10 maximum reward + 30% = €13 commitment, from the shared FeeCalculator.
        $reopened->assertJsonPath('data.cost_preview.basis', 'maximum_discount')
            ->assertJsonPath('data.cost_preview.per_voucher', ['reward_minor' => 1000, 'fee_minor' => 300, 'merchant_commitment_minor' => 1300])
            ->assertJsonPath('data.cost_preview.allocation.merchant_commitment_minor', 650000);
    }

    public function test_e2e1_three_offers_each_show_only_their_own_terms(): void
    {
        $ids = collect(['A', 'B', 'C'])->map(fn ($n, $i) => $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, 'name' => "Offer {$n}", 'discount_value' => 500 * ($i + 1)])->json('data.id'));

        $this->assertCount(3, $this->getJson($this->url('/offers'))->assertOk()->json('data'));
        foreach ($ids as $i => $id) {
            $this->getJson($this->url("/offers/{$id}"))->assertJsonPath('data.terms.name', 'Offer '.['A', 'B', 'C'][$i])
                ->assertJsonPath('data.terms.discount_value', 500 * ($i + 1));
        }
        $this->assertSame(['Offer B'], array_column(array_column($this->getJson($this->url('/offers?search=b'))->json('data'), 'terms'), 'name'));
    }

    public function test_e2e3_editing_an_active_offer_creates_a_new_version_and_keeps_the_old_one(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');

        // Draft edits change version 1 in place.
        $this->patchJson($this->url("/offers/{$id}"), ['name' => 'Woon & Co: 10% (draft edit)'])->assertOk()->assertJsonPath('data.terms.version', 1);

        $this->postJson($this->url("/offers/{$id}/activate"))->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.terms.locked', true);

        $this->patchJson($this->url("/offers/{$id}"), ['discount_value' => 1500])->assertOk()
            ->assertJsonPath('data.terms.version', 2)->assertJsonPath('data.terms.discount_value', 1500)->assertJsonPath('data.terms.locked', true)
            ->assertJsonPath('data.terms.max_discount_minor', 1000); // unchanged terms carried over

        $v1 = OfferVersion::where('version', 1)->sole();
        $this->assertSame(1000, $v1->discount_value);
        $this->assertSame(['wonen'], $v1->categories);
        $this->assertSame(['wonen'], OfferVersion::where('version', 2)->sole()->categories);
        $this->assertSame([1, 2], array_column($this->getJson($this->url("/offers/{$id}"))->json('versions'), 'version'));

        $this->expectException(LogicException::class);
        $v1->update(['discount_value' => 9999]);
    }

    public function test_status_machine_and_activation_rules(): void
    {
        $id = $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, 'max_discount_minor' => null])->json('data.id');

        $this->postJson($this->url("/offers/{$id}/activate"))->assertStatus(422)
            ->assertJsonPath('error.code', 'offer_incomplete')->assertJsonPath('error.details.missing_fields', ['max_discount_minor']);
        $this->postJson($this->url("/offers/{$id}/pause"))->assertStatus(409)->assertJsonPath('error.code', 'invalid_status_transition');

        $this->patchJson($this->url("/offers/{$id}"), ['max_discount_minor' => 1000])->assertOk();
        $this->postJson($this->url("/offers/{$id}/activate"))->assertOk();
        $this->postJson($this->url("/offers/{$id}/pause"))->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson($this->url("/offers/{$id}/activate"))->assertOk()->assertJsonPath('data.status', 'active'); // resume
        $this->postJson($this->url("/offers/{$id}/end"))->assertOk()->assertJsonPath('data.status', 'ended');
        $this->postJson($this->url("/offers/{$id}/activate"))->assertStatus(409);
        $this->patchJson($this->url("/offers/{$id}"), ['name' => 'x x'])->assertStatus(409)->assertJsonPath('error.code', 'offer_ended');

        $this->assertSame(4, AuditEvent::where('action', 'offer.status_changed')->count()); // refused transitions change nothing
    }

    public function test_wallet_purchase_needs_price_and_has_no_merchant_fee_preview(): void
    {
        $data = ['acquisition' => 'wallet_purchase', 'name' => 'Cadeaubon €20', 'discount_type' => 'fixed', 'discount_value' => 2000, 'redemption_validity_days' => 365];
        $id = $this->postJson($this->url('/offers'), $data)->assertCreated()
            ->assertJsonPath('data.cost_preview', ['applies' => false, 'reason' => 'consumer_funded'])->json('data.id');

        $this->postJson($this->url("/offers/{$id}/activate"))->assertStatus(422)->assertJsonPath('error.details.missing_fields', ['price_minor']);
    }

    public function test_activation_requires_a_commercially_active_webshop_but_drafts_do_not(): void
    {
        $onboarding = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id]);

        $id = $this->postJson($this->url('/offers', $onboarding), self::TEN_PERCENT)->assertCreated()->json('data.id');
        $this->postJson($this->url("/offers/{$id}/activate", $onboarding))->assertForbidden()->assertJsonPath('error.code', 'workspace_not_active');
    }

    public function test_validation(): void
    {
        $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, 'discount_value' => 15000])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['discount_value']]]);
        $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, 'acquisition' => 'gift', 'currency' => 'USD'])->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['acquisition', 'currency']]]);
        $this->postJson($this->url('/offers'), [...self::TEN_PERCENT, 'starts_at' => '2026-12-01', 'ends_at' => '2026-11-01'])->assertStatus(422);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $id = $this->postJson($this->url('/offers'), self::TEN_PERCENT)->json('data.id');

        $employee = User::factory()->create();
        $this->shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'permissions' => ['offers.view'], 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        Sanctum::actingAs($employee);
        $this->getJson($this->url("/offers/{$id}"))->assertOk();
        $this->patchJson($this->url("/offers/{$id}"), ['name' => 'Hijack'])->assertForbidden();

        $other = User::factory()->create();
        $otherShop = $this->activeWebshop($other);
        Sanctum::actingAs($other);
        $this->getJson($this->url("/offers/{$id}", $otherShop))->assertNotFound();
        $this->postJson($this->url("/offers/{$id}/end", $otherShop))->assertNotFound();
        $this->assertSame('draft', Offer::where('public_id', $id)->sole()->status->value);
    }

    public function test_fee_calculator_is_30_percent_of_the_reward_with_half_up_rounding(): void
    {
        $fees = app(FeeCalculator::class);

        $this->assertSame(300, $fees->feeOnReward(1000));   // €10 → €3
        $this->assertSame(0, $fees->feeOnReward(0));
        $this->assertSame(1, $fees->feeOnReward(2));       // 0.6 → 1
        $this->assertSame(0, $fees->feeOnReward(1));       // 0.3 → 0
        $this->assertSame(2, $fees->feeOnReward(5));       // 1.5 → 2 (half up)
        $this->assertSame(300000, $fees->feeOnReward(1000000));

        config(['shopbonus.fees.direct_reward_fee_bps' => 2500]);
        $this->assertSame(250, $fees->feeOnReward(1000));
    }
}
