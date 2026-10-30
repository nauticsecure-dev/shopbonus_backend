<?php

namespace Tests\Feature\Enrichment;

use App\Enums\PlatformRole;
use App\Enums\WebshopStatus;
use App\Models\MerchantEnrichmentRun;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Enrichment\EnrichmentPipeline;
use App\Services\Enrichment\SafeUrlFetcher;
use App\Services\Onboarding\Network\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\Fakes\FakeHostResolver;
use Tests\TestCase;

class MerchantEnrichmentE2ETest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private FakeHostResolver $dns;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = new FakeHostResolver;
        $this->app->instance(HostResolver::class, $this->dns);
        config([
            'enrichment.enabled' => true,
            'enrichment.api_key' => 'test-openai-key',
            'enrichment.text_model' => 'fixture-enrichment-text',
            'enrichment.image_model' => 'fixture-enrichment-image',
            'enrichment.auto_publish' => false,
            'enrichment.daily_run_limit' => 20,
            'enrichment.fetch_max_bytes' => 65536,
        ]);
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs($this->admin);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    /** @param array<string, mixed> $overrides */
    private function proposalJson(array $overrides = []): string
    {
        return json_encode(array_merge([
            'short_description' => 'Stijlvolle woonartikelen en accessoires online.',
            'description' => 'Woon & Co verkoopt volgens de website woonaccessoires en cadeaus. Assortiment en voorwaarden volgen de eigen webshop.',
            'categories' => ['home_garden'],
            'review_required' => true,
            'risk_level' => 'medium',
            'evidence_refs' => ['E1', 'E3'],
            'uncertainty' => 'Alleen homepage-tekst beschikbaar; geen productdetail bevestigd.',
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private function fakeAi(?string $proposalJson = null, bool $imageOk = true): void
    {
        $proposalJson ??= $this->proposalJson();
        Http::fake([
            'https://woonenco.nl/*' => Http::response('<html><head><title>Woon & Co</title><meta name="description" content="Woonaccessoires"></head><body><p>Stijlvolle woonartikelen online.</p></body></html>'),
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => $proposalJson]],
                ]],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 80],
            ]),
            'https://api.openai.com/v1/images/generations' => $imageOk
                ? Http::response(['data' => [['b64_json' => base64_encode('fake-webp-bytes')]]])
                : Http::response(['error' => 'rate limit'], 429),
        ]);
    }

    private function eligibleShop(): Webshop
    {
        $owner = User::factory()->create();

        return $this->activeWebshop($owner, ['name' => 'Woon & Co', 'verified_domain' => 'woonenco.nl']);
    }

    public function test_ssrf_block_never_fetches_private_hosts(): void
    {
        Http::fake();
        $this->dns->addresses['evil.example.nl'] = ['169.254.169.254'];
        $fetcher = app(SafeUrlFetcher::class);

        $this->assertNull($fetcher->fetch('https://evil.example.nl/', 4096));
        Http::assertNothingSent();
    }

    public function test_pipeline_completes_with_faked_ai_and_admin_approve_publishes_profile(): void
    {
        $this->dns->addresses['woonenco.nl'] = ['93.184.216.34'];
        $this->fakeAi();
        $shop = $this->eligibleShop();

        $run = app(EnrichmentPipeline::class)->enqueue($shop, 'scheduled');
        $run->refresh();
        $this->assertSame('awaiting_review', $run->status);
        $this->assertTrue($run->review_required);
        $this->assertNotNull($run->proposal);
        $this->assertTrue($run->cover_path !== null);
        $this->assertNull($run->cover_failure_code);

        $this->asAdmin();
        $this->getJson('/api/v1/admin/merchant-enrichments')->assertOk()->assertJsonPath('availability.configured', true);
        $this->postJson('/api/v1/admin/merchant-enrichments/'.$run->public_id.'/approve', [
            'note' => 'Homepage copy matches verified domain.',
        ])->assertOk()->assertJsonPath('data.status', 'published');

        $profile = WebshopPublicProfile::where('webshop_id', $shop->id)->firstOrFail();
        $this->assertSame('Stijlvolle woonartikelen en accessoires online.', $profile->short_description);
        $this->assertSame(['home_garden'], $profile->categories());
    }

    public function test_image_failure_falls_back_without_failing_enrichment(): void
    {
        $this->dns->addresses['woonenco.nl'] = ['93.184.216.34'];
        $this->fakeAi(proposalJson: $this->proposalJson(['review_required' => false, 'risk_level' => 'low']), imageOk: false);
        config(['enrichment.auto_publish' => false]);
        $shop = $this->eligibleShop();

        $run = app(EnrichmentPipeline::class)->enqueue($shop, 'scheduled')->fresh();
        $this->assertSame('awaiting_review', $run->status);
        $this->assertNull($run->cover_path);
        $this->assertSame('cover_rate_limited', $run->cover_failure_code);
        $this->assertFalse($run->review_required);
    }

    public function test_auto_publish_low_risk_when_enabled(): void
    {
        $this->dns->addresses['woonenco.nl'] = ['93.184.216.34'];
        config(['enrichment.auto_publish' => true]);
        $this->fakeAi($this->proposalJson(['review_required' => false, 'risk_level' => 'low']));
        $shop = $this->eligibleShop();

        $run = app(EnrichmentPipeline::class)->enqueue($shop, 'scheduled')->fresh();
        $this->assertSame('published', $run->status);
        $this->assertNotNull($run->published_at);
        $this->assertNotNull(WebshopPublicProfile::where('webshop_id', $shop->id)->value('short_description'));
    }

    public function test_retry_after_failure(): void
    {
        $owner = User::factory()->create();
        $shop = Webshop::factory()->create([
            'created_by' => $owner->id,
            'status' => WebshopStatus::Approved,
            'verified_domain' => 'woonenco.nl',
            'ownership_verified_at' => now(),
        ]);
        $failed = MerchantEnrichmentRun::create([
            'webshop_id' => $shop->id,
            'status' => 'failed',
            'trigger' => 'scheduled',
            'source_url' => 'https://woonenco.nl/',
            'research' => ['schema_version' => 'merchant-enrichment-research-v1', 'source_url' => 'https://woonenco.nl/', 'title' => 'x', 'meta_description' => null, 'excerpt' => 'x', 'evidence' => ['E3' => ['fact' => 'visible_text_excerpt', 'value' => 'x']]],
            'research_hash' => hash('sha256', 'x'),
            'text_model' => 'fixture-enrichment-text',
            'review_required' => true,
            'failure_code' => 'provider_unavailable',
            'finished_at' => now(),
        ]);

        $this->dns->addresses['woonenco.nl'] = ['93.184.216.34'];
        $this->fakeAi();
        $this->asAdmin();
        $this->postJson('/api/v1/admin/merchant-enrichments/'.$failed->public_id.'/retry')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'awaiting_review');
    }
}
