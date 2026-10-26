<?php

namespace Tests\Feature\Banners;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OfferAcquisition;
use App\Enums\UserStatus;
use App\Jobs\GenerateBannerProposals;
use App\Models\BannerAiRequest;
use App\Models\BannerCampaign;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Banners\BannerAi;
use App\Services\Banners\BannerService;
use App\Services\Offers\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Support\BannerFixture;
use Tests\TestCase;

class BannerAiTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private User $owner;

    private BannerCampaign $campaign;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        config(['banner_ai.enabled' => true, 'banner_ai.api_key' => 'fixture-secret', 'banner_ai.model' => 'configured-fixture-model']);
        Bus::fake([GenerateBannerProposals::class]);
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $shop = $this->activeWebshop($this->owner, ['verified_domain' => 'example.nl']);
        $this->campaign = app(BannerService::class)->create($shop, 'Fixture', null, BannerFixture::config(), $this->owner->id);
        $this->url = "/api/v1/workspaces/{$shop->public_id}/banners/{$this->campaign->public_id}/ai-requests";
        Sanctum::actingAs($this->owner);
    }

    private function requestRun(string $kind = 'designs', ?string $key = null): BannerAiRequest
    {
        $id = $this->postJson($this->url, ['kind' => $kind, 'request_key' => $key ?? (string) Str::uuid()])
            ->assertStatus(202)->assertJsonPath('data.human_review_required', true)->json('data.id');

        return BannerAiRequest::where('public_id', $id)->firstOrFail();
    }

    public function test_private_editable_designs_require_review_and_never_publish_or_change_source(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(BannerFixture::output())]);
        $run = $this->requestRun();
        app(BannerAi::class)->execute($run->id);
        app(BannerAi::class)->execute($run->id);
        Http::assertSentCount(1);
        $this->getJson($this->url.'/'.$run->public_id)->assertOk()->assertJsonCount(3, 'data.proposals')->assertJsonPath('data.measured_uplift', null);
        $draftId = $this->postJson($this->url.'/'.$run->public_id.'/review', ['decision' => 'accept', 'proposal' => 0, 'note' => 'Review as a private test'])
            ->assertOk()->assertJsonPath('data.published', false)->json('data.draft_campaign');
        $draft = BannerCampaign::where('public_id', $draftId)->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertEquals($this->campaign->draft_config['content'], $draft->draft_config['content']);
        $this->assertNull($draft->published_version_id);
        $this->assertSame(1, $this->campaign->fresh()->draft_revision);
        $this->assertDatabaseCount('banner_versions', 0);
        $this->postJson($this->url.'/'.$run->public_id.'/review', ['decision' => 'accept', 'proposal' => 1, 'note' => 'again'])->assertStatus(409);
        Http::assertSent(function ($r) {
            $json = $r->data();
            $this->assertFalse($json['store']);
            $this->assertArrayNotHasKey('tools', $json);
            $this->assertStringNotContainsString('example.nl', $json['input']);
            $this->assertStringNotContainsString('Original terms', $json['input']);

            return true;
        });
    }

    public function test_insights_use_bounded_verified_tenant_aggregates_and_no_customer_data(): void
    {
        $shop = Webshop::findOrFail($this->campaign->webshop_id);
        $this->postJson("/api/v1/workspaces/{$shop->public_id}/connections", ['platform' => 'prestashop'])->assertCreated();
        $connection = WebshopConnection::sole();
        foreach (range(1, 10) as $n) {
            WebshopOrder::create(['webshop_id' => $shop->id, 'connection_id' => $connection->id, 'platform' => 'prestashop', 'external_order_id' => 'paid-'.$n,
                'ingestion_status' => 'complete', 'currency' => 'EUR', 'amount_minor' => 10000, 'refunded_minor' => 1000,
                'payment_status' => 'paid', 'paid_at' => now(), 'customer_email' => 'never-send@example.test']);
        }
        WebshopOrder::create(['webshop_id' => $shop->id, 'connection_id' => $connection->id, 'platform' => 'prestashop', 'external_order_id' => 'unpaid',
            'ingestion_status' => 'complete', 'currency' => 'EUR', 'amount_minor' => 999999, 'payment_status' => 'pending']);
        Http::fake(['api.openai.com/*' => Http::response(BannerFixture::output('insights'))]);
        $run = $this->requestRun('insights');
        $this->assertSame(10, $run->context['evidence']['E3']['sample_size']);
        $this->assertSame(90000, $run->context['evidence']['E3']['net_revenue_minor']);
        app(BannerAi::class)->execute($run->id);
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSent(function ($r) {
            $this->assertStringNotContainsString('never-send', $r['input']);
            $this->assertStringNotContainsString('external_order_id', $r['input']);

            return true;
        });
        $employee = User::factory()->create();
        $shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee, 'status' => MembershipStatus::Active, 'permissions' => ['offers.manage']]);
        Sanctum::actingAs($employee);
        $this->getJson($this->url.'/'.$run->public_id)->assertStatus(403);
        $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_offer_cost_is_authoritative_and_changed_offer_version_blocks_acceptance(): void
    {
        $shop = Webshop::findOrFail($this->campaign->webshop_id);
        $offers = app(OfferService::class);
        $offer = $offers->create($shop->memberships()->sole()->setRelation('webshop', $shop), OfferAcquisition::MerchantReward,
            ['name' => 'Reward', 'discount_type' => 'fixed', 'discount_value' => 1000, 'min_order_minor' => 10000, 'redemption_validity_days' => 90]);
        $this->campaign->update(['offer_id' => $offer->id]);
        Http::fake(['api.openai.com/*' => Http::response(BannerFixture::output())]);
        $run = $this->requestRun();
        $this->assertSame(1300, $run->context['evidence']['E2']['total_minor']);
        app(BannerAi::class)->execute($run->id);
        // A later offer snapshot must invalidate the old cost/terms evidence.
        $version = $offer->currentVersion->replicate();
        $version->version = $offer->currentVersion->version + 1;
        $version->save();
        $offer->update(['current_version_id' => $version->id]);
        $this->postJson($this->url.'/'.$run->public_id.'/review', ['decision' => 'accept', 'proposal' => 0, 'note' => 'Changed offer'])->assertStatus(409);
    }

    public function test_request_idempotency_conflicts_and_global_quota(): void
    {
        config(['banner_ai.daily_request_limit' => 1]);
        $key = (string) Str::uuid();
        $run = $this->requestRun(key: $key);
        $this->assertSame($run->id, $this->requestRun(key: $key)->id);
        Bus::assertDispatchedTimes(GenerateBannerProposals::class, 1);
        $this->postJson($this->url, ['kind' => 'insights', 'request_key' => $key])->assertStatus(409);
        $this->postJson($this->url, ['kind' => 'designs', 'request_key' => (string) Str::uuid()])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(1, DB::table('banner_ai_quotas')->value('requests'));
        Http::assertNothingSent();
    }

    public function test_configuration_and_insufficient_insight_evidence_fail_without_reserving_quota(): void
    {
        config(['banner_ai.enabled' => false]);
        $this->postJson($this->url, ['kind' => 'designs', 'request_key' => (string) Str::uuid()])->assertStatus(503);
        config(['banner_ai.enabled' => true]);
        $this->postJson($this->url, ['kind' => 'insights', 'request_key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('error.code', 'banner_ai_insufficient_evidence');
        $this->assertDatabaseCount('banner_ai_requests', 0);
        $this->assertSame(0, (int) DB::table('banner_ai_quotas')->sum('requests'));
    }

    public function test_authentication_permissions_cross_tenant_and_validation_are_enforced(): void
    {
        $this->postJson($this->url, ['kind' => 'designs', 'request_key' => 'bad'])->assertStatus(422);
        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson($this->url)->assertStatus(404);
        $shop = $this->campaign->webshop_id;
        DB::table('webshop_memberships')->insert(['webshop_id' => $shop, 'user_id' => $other->id, 'role' => MembershipRole::Employee->value, 'status' => MembershipStatus::Active->value, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson($this->url, ['kind' => 'designs', 'request_key' => (string) Str::uuid()])->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url)->assertStatus(401);
    }

    public function test_execution_rechecks_revoked_access_before_any_egress(): void
    {
        $run = $this->requestRun();
        $this->owner->forceFill(['status' => UserStatus::Suspended])->save();
        app(BannerAi::class)->execute($run->id);
        $this->assertSame('requester_no_longer_authorized', $run->fresh()->failure_code);
        Http::assertNothingSent();
    }

    public function test_context_tampering_is_detected_without_egress(): void
    {
        $run = $this->requestRun();
        DB::table('banner_ai_requests')->where('id', $run->id)->update(['context' => json_encode(['evidence' => ['E1' => ['injected' => 'private']]])]);
        app(BannerAi::class)->execute($run->id);
        $this->assertSame('context_integrity_failed', $run->fresh()->failure_code);
        Http::assertNothingSent();
    }

    public function test_stale_source_conflicts_and_rejection_creates_no_draft(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(BannerFixture::output())]);
        $run = $this->requestRun();
        app(BannerAi::class)->execute($run->id);
        app(BannerService::class)->saveDraft($this->campaign, $this->campaign->draft_config, 1, $this->owner->id);
        $this->postJson($this->url.'/'.$run->public_id.'/review', ['decision' => 'accept', 'proposal' => 0, 'note' => 'stale'])->assertStatus(409);
        $this->postJson($this->url.'/'.$run->public_id.'/review', ['decision' => 'reject', 'note' => 'No longer relevant'])->assertOk();
        $this->assertDatabaseCount('banner_campaigns', 1);
        $this->assertDatabaseCount('banner_ai_reviews', 1);
    }

    public function test_refusal_tools_incomplete_and_invalid_evidence_are_rejected_safely(): void
    {
        $invalid = BannerFixture::output();
        $data = json_decode($invalid['output'][0]['content'][0]['text'], true);
        $data['proposals'][0]['evidence_refs'] = ['FAKE'];
        $invalid['output'][0]['content'][0]['text'] = json_encode($data);
        $responses = [
            ['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => 'publish']]],
            ['status' => 'incomplete', 'output' => []],
            ['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'no']]]]],
            $invalid,
        ];
        Http::fake(['api.openai.com/*' => Http::sequence()->push($responses[0])->push($responses[1])->push($responses[2])->push($responses[3])]);
        foreach ($responses as $_) {
            $run = $this->requestRun();
            app(BannerAi::class)->execute($run->id);
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertSame('invalid_provider_output', $run->fresh()->failure_code);
        }
        $this->assertDatabaseCount('banner_ai_results', 0);
        Http::assertSentCount(4);
    }

    public function test_provider_errors_are_safe_single_attempts_and_still_consume_quota(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'fixture-secret']], 429)]);
        $run = $this->requestRun();
        app(BannerAi::class)->execute($run->id);
        app(BannerAi::class)->execute($run->id);
        $this->assertSame('provider_rate_limited', $run->fresh()->failure_code);
        $this->assertSame(1, DB::table('banner_ai_quotas')->value('requests'));
        Http::assertSentCount(1);
        $this->getJson($this->url.'/'.$run->public_id)->assertOk()->assertDontSee('fixture-secret');
    }
}
