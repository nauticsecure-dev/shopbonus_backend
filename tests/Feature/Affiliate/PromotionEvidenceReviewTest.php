<?php

namespace Tests\Feature\Affiliate;

use App\Enums\OfferAcquisition;
use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Models\AffiliateConnection;
use App\Models\AffiliateProgram;
use App\Models\AffiliatePromotionReview;
use App\Models\Offer;
use App\Models\User;
use App\Services\Affiliate\PromotionEvidenceReview;
use App\Services\Enrichment\WebsiteResearcher;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class PromotionEvidenceReviewTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $admin;

    private Offer $offer;

    private AffiliateProgram $program;

    private array $mapping = ['resource' => 'promotions', 'record' => '/0', 'program' => '/program_id', 'conditions' => '/conditions', 'exclusions' => '/exclusions'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $shop = $this->activeWebshop($this->admin);
        $this->offer = app(OfferService::class)->create($shop->memberships()->sole()->setRelation('webshop', $shop), OfferAcquisition::PromotionalCode, ['name' => 'Synthetic promotion', 'discount_type' => 'percentage', 'discount_value' => 1000, 'combinable' => false]);
        $c = AffiliateConnection::create(['network' => 'daisycon', 'name' => 'Synthetic', 'status' => 'connected', 'credentials' => ['publisher_id' => '123', 'access_token' => 'SYNTHETIC'], 'postback_token_hash' => hash('sha256', 'synthetic')]);
        $this->program = AffiliateProgram::create(['connection_id' => $c->id, 'external_id' => 'P1', 'webshop_id' => $shop->id, 'name' => 'Synthetic', 'active' => true, 'commission_type' => 'percentage', 'commission_value' => 1000, 'currency' => 'EUR', 'cashback_allowed' => false, 'tracking_url_template' => 'https://example.test']);
        DB::table('affiliate_provider_resources')->insert(['connection_id' => $c->id, 'resource_key' => 'promotions', 'credential_hash' => app(WebsiteResearcher::class)->hash($c->credentials), 'payload' => json_encode([['program_id' => 'P1', 'conditions' => ['Minimum purchase EUR 50'], 'exclusions' => ['Gift cards'], 'allowed' => true]]), 'fetched_at' => now(), 'updated_at' => now()]);
    }

    private function service(): PromotionEvidenceReview
    {
        return app(PromotionEvidenceReview::class);
    }

    private function error(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected '.$code);
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_approved_normalized_terms_create_one_version_without_permission_or_activation(): void
    {
        $old = $this->offer->currentVersion;
        $r = $this->service()->propose($this->offer, $this->program, $this->mapping, $this->admin);
        $this->assertSame('pending', $r->status);
        $this->assertSame($old->id, $this->offer->fresh()->current_version_id);
        $r = $this->service()->review($r, $this->admin, true, 'Reviewed genuine field mapping, no cashback approval');
        $current = $this->offer->fresh()->currentVersion;
        $this->assertStringContainsString('Exclusions:', $current->public_description);
        $this->assertFalse($current->combinable);
        $this->assertNull($current->cashback_permission);
        $this->assertFalse($this->program->fresh()->cashback_allowed);
        $this->assertSame('draft', $this->offer->fresh()->status->value);
        $this->assertSame($old->public_description, $old->fresh()->public_description);
        $this->service()->review($r, $this->admin, true, 'Retry');
        $this->assertSame(2, $this->offer->versions()->count());
        $this->assertDatabaseCount('affiliate_reward_rules', 0);
    }

    public function test_changed_and_failed_source_cannot_be_approved(): void
    {
        $r = $this->service()->propose($this->offer, $this->program, $this->mapping, $this->admin);
        DB::table('affiliate_provider_resources')->update(['payload' => json_encode([['changed' => true]])]);
        $this->error('promotion_evidence_stale', fn () => $this->service()->review($r, $this->admin, true, 'Review'));
        DB::table('affiliate_provider_resources')->update(['last_error' => 'provider_failed']);
        $this->error('promotion_snapshot_unavailable', fn () => $this->service()->review($r, $this->admin, true, 'Review'));
        $this->assertSame(1, $this->offer->versions()->count());
        $this->assertSame('rejected', $this->service()->review($r, $this->admin, false, 'Source changed')->status);
    }

    public function test_missing_exclusions_and_foreign_program_are_never_guessed(): void
    {
        $this->error('promotion_field_missing', fn () => $this->service()->propose($this->offer, $this->program, ['exclusions' => '/unknown'] + $this->mapping, $this->admin));
        $this->program->forceFill(['external_id' => 'foreign'])->save();
        $this->error('promotion_program_mismatch', fn () => $this->service()->propose($this->offer, $this->program, $this->mapping, $this->admin));
    }

    public function test_revoked_admin_and_stale_offer_revision_cannot_approve(): void
    {
        $r = $this->service()->propose($this->offer, $this->program, $this->mapping, $this->admin);
        $this->admin->platformRoles()->delete();
        $this->error('forbidden', fn () => $this->service()->review($r, $this->admin, true, 'Review'));
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->offer->forceFill(['current_version_id' => null])->save();
        $this->error('promotion_evidence_stale', fn () => $this->service()->review($r, $this->admin, true, 'Review'));
    }

    public function test_operator_mapping_workflow_and_expired_snapshot(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'promotion-map-');
        try {
            file_put_contents($file, json_encode($this->mapping));
            $this->artisan('shopbonus:promotion-evidence', ['action' => 'propose', 'id' => $this->offer->public_id, '--actor' => $this->admin->public_id, '--program' => $this->program->public_id, '--file' => $file])->assertSuccessful();
            $r = AffiliatePromotionReview::sole();
            $this->artisan('shopbonus:promotion-evidence', ['action' => 'show', 'id' => $r->public_id, '--actor' => $this->admin->public_id])->assertSuccessful();
            DB::table('affiliate_provider_resources')->update(['fetched_at' => now()->subDays(8)]);
            $this->error('promotion_snapshot_unavailable', fn () => $this->service()->review($r, $this->admin, true, 'Expired source'));
            $this->artisan('shopbonus:promotion-evidence', ['action' => 'reject', 'id' => $r->public_id, '--actor' => $this->admin->public_id, '--note' => 'Outdated evidence'])->assertSuccessful();
            $this->error('promotion_review_completed', fn () => $this->service()->review($r, $this->admin, true, 'Cannot reverse review'));
        } finally {
            unlink($file);
        }
    }
}
