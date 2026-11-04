<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Models\CmsAiSuggestion;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\User;
use App\Services\Cms\CmsAiReviewService;
use App\Services\Cms\CmsService;
use App\Services\Cms\CmsSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CmsAiSafeguardsTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureLegalVersions();
        $this->admin = $this->admin();
        config(['cms.ai_enabled' => true, 'cms.api_key' => 'sk-cms-synthetic', 'cms.model' => 'gpt-test', 'cms.daily_limit' => 20, 'cms.daily_budget_micros' => 10000000, 'cms.queue_connection' => 'sync', 'cms.auto_translate' => false,
            'cms.pricing' => ['model' => 'gpt-test', 'currency' => 'EUR', 'reference' => 'Synthetic fixture, not live pricing', 'valid_until' => now()->utc()->addDays(7)->toDateString(), 'context_tokens' => 131072, 'input_micros_per_million' => 1000000, 'output_micros_per_million' => 1000000]]);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);

        return $u;
    }

    private function as(User $u): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function page(string $key = 'safe-copy', bool $landing = false): CmsPage
    {
        $content = ['title' => 'Welkom', 'body' => ['type' => 'doc', 'content' => []]];
        if ($landing) {
            $content += ['hero' => ['heading' => 'Welkom'], 'nav' => ['links' => [['label' => 'Voordelen', 'href' => '/voordelen']]]];
        }

        return app(CmsService::class)->create($key, $landing ? 'landing' : 'info', 'public', ['nl' => ['slug' => $key, 'content' => $content]], $this->admin->id);
    }

    private function provider(): void
    {
        Http::fake(['api.openai.com/*' => function ($r) {
            $i = json_decode($r['input'], true);

            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5], 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['key' => $i['key'], 'source_hash' => $i['source_hash'], 'locale' => $i['locale'], 'segments' => array_column($i['segments'], 'text'), 'review' => true, 'warnings' => []])]]]]]);
        }]);
    }

    private function proposal(CmsPage $p, string $key = 'proposal'): CmsAiSuggestion
    {
        return app(CmsSuggestions::class)->request($p, 'improve', 'nl', 'nl', $key, $this->admin);
    }

    public function test_all_legacy_editors_use_only_scoped_revision_backed_responses_and_dedupe(): void
    {
        $this->provider();
        $this->as($this->admin);
        foreach (['homepage', 'over-ons', 'voor-webshops'] as $key) {
            $page = $this->page($key, true);
            $original = CmsPageLocale::where('page_id', $page->id)->sole()->draft_content;
            $r = $this->postJson('/api/v1/admin/content/'.$key.'/ai-suggest', ['section' => 'hero', 'locale' => 'nl', 'focus' => 'Korter', 'request_key' => 'legacy-'.$key])->assertOk()->assertJsonPath('data.applied', false)->assertJsonPath('data.proposal.independent_review_required', true);
            $s = CmsAiSuggestion::where('public_id', $r->json('data.proposal.id'))->sole();
            $this->assertSame($original['nav'], $s->proposed['nav']);
            $this->postJson('/api/v1/admin/content/'.$key.'/ai-suggest', ['section' => 'hero', 'locale' => 'nl', 'focus' => 'Korter', 'request_key' => 'legacy-'.$key])->assertOk()->assertJsonPath('data.proposal.id', $s->public_id);
            $this->assertSame(1, CmsPageLocale::where('page_id', $page->id)->sole()->draft_revision);
        }
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'chat/completions'));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/responses' && $r['service_tier'] === 'default' && array_column(json_decode($r['input'], true)['segments'], 'path') === ['hero.heading']);
    }

    public function test_budget_reservation_precedes_execution_and_cap_blocks_second_provider_call(): void
    {
        $this->provider();
        config(['cms.daily_budget_micros' => 135072]);
        $s = $this->proposal($this->page());
        $this->assertSame(135072, $s->reserved_micros);
        $this->assertSame(15, $s->estimated_micros);
        $this->assertSame(135072, (int) DB::table('cms_ai_quotas')->sole()->reserved_micros);
        try {
            $this->proposal($this->page('second-copy'), 'second');
            $this->fail('Budget exceeded.');
        } catch (ApiException) {
            $this->assertDatabaseHas('cms_ai_suggestions', ['error_code' => 'cms_ai_budget_exhausted']);
        }
        Http::assertSentCount(1);
        $this->assertSame(135072, (int) DB::table('cms_ai_quotas')->sole()->reserved_micros);
        app(CmsSuggestions::class)->process($s->id);
        Http::assertSentCount(1);
    }

    public function test_timeout_holds_money_and_does_not_retry_or_refund_it(): void
    {
        config(['cms.daily_budget_micros' => 135072]);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Synthetic timeout');
        });
        foreach (['first', 'second'] as $key) {
            try {
                $this->proposal($this->page($key), $key);
                $this->fail('Failed provider accepted.');
            } catch (ApiException) {
                $this->assertSame(135072, (int) DB::table('cms_ai_quotas')->sole()->reserved_micros);
            }
        }
        $this->assertSame(1, $attempts);
        $this->assertDatabaseHas('cms_ai_suggestions', ['status' => 'failed', 'reserved_micros' => 135072]);
    }

    public function test_missing_stale_or_wrong_model_prices_fail_before_network(): void
    {
        Http::fake();
        $base = config('cms.pricing');
        foreach ([[], array_replace($base, ['model' => 'other-model']), array_replace($base, ['valid_until' => now()->subDay()->toDateString()]), array_replace($base, ['valid_until' => 'tomorrow'])] as $i => $profile) {
            config(['cms.pricing' => $profile]);
            try {
                $this->proposal($this->page('price-'.$i), 'price-'.$i);
                $this->fail('Unverified pricing accepted.');
            } catch (ApiException) {
                $this->assertDatabaseHas('cms_ai_suggestions', ['request_key' => 'price-'.$i, 'error_code' => 'cms_budget_not_configured']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_independent_review_is_required_and_self_review_cannot_apply(): void
    {
        $this->provider();
        $page = $this->page();
        $s = $this->proposal($page);
        $this->as($this->admin);
        $this->postJson('/api/v1/admin/cms/suggestions/'.$s->public_id.'/apply')->assertConflict()->assertJsonPath('error.code', 'cms_review_required');
        $body = ['locale' => 'nl', 'language_correct' => true, 'meaning_preserved' => true, 'note' => 'Synthetic independent Dutch review.'];
        $url = '/api/v1/admin/cms/suggestions/'.$s->public_id.'/review';
        $this->postJson($url, $body)->assertForbidden();
        $reviewer = $this->admin();
        $this->as($reviewer);
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.accepted', true);
        $this->postJson($url, $body)->assertConflict();
        $this->as($this->admin);
        $this->postJson('/api/v1/admin/cms/suggestions/'.$s->public_id.'/apply')->assertOk();
        $this->assertNull(CmsPageLocale::where('page_id', $page->id)->sole()->published_revision_id);
    }

    public function test_negative_review_revoked_reviewer_and_tampered_proposals_cannot_apply(): void
    {
        $this->provider();
        foreach (['negative', 'revoked', 'tampered'] as $case) {
            $s = $this->proposal($this->page($case), $case);
            $reviewer = $this->admin();
            app(CmsAiReviewService::class)->review($s, $reviewer, 'nl', $case !== 'negative', 'Synthetic meaning/language verdict.');
            if ($case === 'revoked') {
                $reviewer->platformRoles()->delete();
            }
            if ($case === 'tampered') {
                $content = $s->proposed;
                $content['title'] = 'Tampered';
                $s->update(['proposed' => $content]);
            }
            try {
                app(CmsSuggestions::class)->apply($s, $this->admin, null);
                $this->fail('Invalid review accepted.');
            } catch (ApiException $e) {
                $this->assertSame(409, $e->status);
            }
        }
    }

    public function test_usage_beyond_the_verified_profile_blocks_further_spending(): void
    {
        Http::fake(fn () => Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 131073, 'output_tokens' => 5], 'output' => []]));
        try {
            $this->proposal($this->page());
            $this->fail('Usage beyond verified context accepted.');
        } catch (ApiException) {
            $this->assertTrue((bool) DB::table('cms_ai_quotas')->sole()->spend_blocked);
            $this->assertDatabaseHas('cms_ai_suggestions', ['error_code' => 'cms_budget_profile_mismatch']);
        }
        try {
            $this->proposal($this->page('blocked-future'), 'blocked-future');
            $this->fail('Further spending was permitted.');
        } catch (ApiException) {
            Http::assertSentCount(1);
        }
    }

    public function test_disabled_legacy_ai_does_not_create_default_content(): void
    {
        $this->as($this->admin);
        config(['cms.ai_enabled' => false]);
        $this->postJson('/api/v1/admin/content/homepage/ai-suggest', ['section' => 'hero'])->assertStatus(503)->assertJsonPath('error.code', 'homepage_ai_not_configured');
        $this->assertDatabaseCount('cms_pages', 0);
    }
}
