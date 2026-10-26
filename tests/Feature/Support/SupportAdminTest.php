<?php

namespace Tests\Feature\Support;

use App\Exceptions\ApiException;
use App\Models\PlatformSettingVersion;
use App\Models\SupportConversation;
use App\Models\SupportReview;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Admin\StepUpCodes;
use App\Services\Support\SupportChat;
use App\Services\Support\SupportPolicy;
use App\Services\Support\SupportReviews;
use App\Services\Support\SupportTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);

        return $u;
    }

    private function asAdmin(User $u, bool $step = true): void
    {
        Sanctum::actingAs($u);
        $this->withHeaders(['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'])
            ->withSession(['auth.password_confirmed_at' => time(), StepUpCodes::SESSION_KEY => $step ? time() : 0]);
    }

    private function policy(array $changes = []): array
    {
        return $changes + ['enabled' => false, 'model' => null, 'languages' => ['en', 'nl'], 'daily_request_limit' => 10, 'timeout_seconds' => 10, 'max_retry' => 1, 'restrictions' => []];
    }

    public function test_e2e_settings_require_test_independent_publish_and_audited_rollback_with_immutable_guards(): void
    {
        Queue::fake();
        $a = $this->admin();
        $b = $this->admin();
        $this->asAdmin($a);
        $base = $this->postJson('/api/v1/admin/platform-settings', ['key' => 'support.ai', 'value' => $this->policy(), 'reason' => 'Initial support configuration'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/platform-settings/'.$base.'/review')->assertOk();
        $this->asAdmin($b);
        $this->postJson('/api/v1/admin/platform-settings/'.$base.'/publish', ['note' => 'Independent test review'])->assertConflict()->assertJsonPath('error.code', 'support_policy_test_required');
        $this->postJson('/api/v1/admin/ai/settings/'.$base.'/test')->assertOk()->assertJsonPath('data.total', 100)->assertJsonPath('data.failed', 0);
        $this->postJson('/api/v1/admin/platform-settings/'.$base.'/publish', ['note' => 'Independent test review'])->assertOk();
        $this->asAdmin($a);
        $restricted = $this->postJson('/api/v1/admin/platform-settings', ['key' => 'support.ai', 'value' => $this->policy(['restrictions' => [['rule_code' => 'NO_UNVERIFIED_REFUND_PROMISE', 'match_type' => 'semantic_claim', 'action' => 'BLOCK', 'enabled' => true]]]), 'reason' => 'Test refund promise restriction'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/ai/settings/'.$restricted.'/test')->assertOk();
        $this->postJson('/api/v1/admin/platform-settings/'.$restricted.'/review')->assertOk();
        $this->postJson('/api/v1/admin/platform-settings/'.$restricted.'/publish', ['note' => 'Self approval must fail'])->assertForbidden();
        $this->asAdmin($b);
        $this->postJson('/api/v1/admin/platform-settings/'.$restricted.'/publish', ['note' => 'Independent restriction review'])->assertOk();
        $consumer = User::factory()->create();
        $m = app(SupportChat::class)->submit($consumer, ['message' => 'I have a refund complaint'], (string) Str::uuid());
        app(SupportChat::class)->execute($m->id);
        $this->assertSame('ESCALATED', $m->fresh()->status); // critical complaint rights cannot be suppressed by a configurable BLOCK.
        $rollback = $this->postJson('/api/v1/admin/platform-settings/'.$base.'/rollback', ['reason' => 'Restore previous support settings'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/ai/settings/'.$rollback.'/test')->assertOk();
        $this->postJson('/api/v1/admin/platform-settings/'.$rollback.'/review')->assertOk();
        $this->asAdmin($a);
        $this->postJson('/api/v1/admin/platform-settings/'.$rollback.'/publish', ['note' => 'Independently approved rollback'])->assertOk();
        $this->assertSame([], app(SupportPolicy::class)->current()['restrictions']);
        $this->assertDatabaseHas('audit_events', ['action' => 'support.policy_tested']);
        $this->assertSame('published', PlatformSettingVersion::where('public_id', $restricted)->sole()->status);
    }

    public function test_e2e_admin_review_reply_metrics_and_step_up_authorization(): void
    {
        Queue::fake();
        $consumer = User::factory()->create();
        $m = app(SupportChat::class)->submit($consumer, ['message' => 'I have a complaint'], (string) Str::uuid());
        app(SupportChat::class)->execute($m->id);
        $review = SupportReview::where('message_id', $m->id)->sole();
        $c = SupportConversation::findOrFail($m->conversation_id);
        $this->getJson('/api/v1/admin/ai/reviews')->assertUnauthorized();
        Sanctum::actingAs($consumer);
        $this->getJson('/api/v1/admin/ai/reviews')->assertForbidden();
        $a = $this->admin();
        $this->asAdmin($a, false);
        $this->getJson('/api/v1/admin/ai/reviews')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/ai/reviews/'.$review->public_id)->assertOk()->assertJsonPath('data.question', 'I have a complaint');
        $this->postJson('/api/v1/admin/ai/reviews/'.$review->public_id.'/resolve', ['outcome' => 'ANSWER_CORRECT', 'note' => 'Human reviewed safely'])->assertStatus(423);
        $this->asAdmin($a);
        $this->postJson('/api/v1/admin/ai/reviews/'.$review->public_id.'/resolve', ['outcome' => 'CONFIRMED_ERROR', 'note' => 'Regression needed'])->assertUnprocessable();
        $this->postJson('/api/v1/admin/ai/reviews/'.$review->public_id.'/resolve', ['outcome' => 'CONFIRMED_ERROR', 'note' => 'Regression approved', 'expected_intent' => 'escalate'])->assertOk()->assertJsonPath('data.regression_added', true);
        $this->postJson('/api/v1/admin/ai/reviews/'.$review->public_id.'/resolve', ['outcome' => 'ANSWER_CORRECT', 'note' => 'Cannot rewrite history'])->assertConflict();
        $this->postJson('/api/v1/admin/ai/conversations/'.$c->public_id.'/reply', ['body' => 'Your complaint has been reviewed by a human.'])->assertCreated()->assertJsonPath('data.sender', 'human_support');
        $this->getJson('/api/v1/admin/ai/operations')->assertOk()->assertJsonPath('data.approved_regressions', 1)->assertJsonPath('data.provider_cost', null);
        $this->assertDatabaseHas('audit_events', ['action' => 'support.human_replied']);
    }

    public function test_approved_human_errors_execute_in_future_policy_tests_and_stale_test_corpus_blocks_publication(): void
    {
        Queue::fake();
        $a = $this->admin();
        $b = $this->admin();
        $settings = app(PlatformSettings::class);
        $policy = app(SupportPolicy::class);
        $v = $settings->draft($a, 'support.ai', $this->policy(), 'Test before human regression');
        $this->assertSame(100, $policy->testVersion($v, $a)['total']);
        $consumer = User::factory()->create();
        $m = app(SupportChat::class)->submit($consumer, ['message' => 'wallet'], (string) Str::uuid());
        app(SupportChat::class)->execute($m->id);
        $review = app(SupportReviews::class)->escalate($consumer, $m->fresh());
        app(SupportReviews::class)->resolve($a, $review, ['outcome' => 'CONFIRMED_ERROR', 'note' => 'Human-approved expected intent', 'expected_intent' => 'reward']);
        $settings->submit($v, $a);
        try {
            $settings->publish($v, $b, 'Must reject stale regression corpus');
            $this->fail('Stale test corpus was accepted');
        } catch (ApiException $e) {
            $this->assertSame('support_policy_test_required', $e->errorCode);
        }
        $fresh = $settings->draft($a, 'support.ai', $this->policy(), 'Retest approved human regression');
        $report = $policy->testVersion($fresh, $a);
        $this->assertSame(101, $report['total']);
        $this->assertSame(1, $report['failed']); // Intent correction is not silently applied to production.
        $racing = $settings->draft($a, 'support.ai', $this->policy(), 'Concurrent corpus change test');
        $changed = false;
        DB::listen(function ($event) use (&$changed, $a, $m) {
            if (! $changed && str_contains($event->sql, 'support_regressions') && str_contains($event->sql, 'join')) {
                $changed = true;
                $additional = SupportReview::create(['message_id' => $m->id, 'trigger' => 'negative_feedback']);
                app(SupportReviews::class)->resolve($a, $additional, ['outcome' => 'CONFIRMED_ERROR', 'note' => 'Approved during test retrieval', 'expected_intent' => 'wallet']);
            }
        });
        try {
            $policy->testVersion($racing, $a);
            $this->fail('Changing corpus was tested as a stable snapshot');
        } catch (ApiException $e) {
            $this->assertSame('support_policy_corpus_changed', $e->errorCode);
        }
        $this->assertTrue($changed);

        $settings->submit($fresh, $a);
        try {
            $settings->publish($fresh, $b, 'Must reject failed human case');
            $this->fail('Failed regression was accepted');
        } catch (ApiException $e) {
            $this->assertSame('support_policy_test_required', $e->errorCode);
        }
    }

    public function test_published_faq_history_and_rollback_are_immutable_audited_and_revision_guarded(): void
    {
        $admin = $this->admin();
        $this->asAdmin($admin);
        $category = $this->postJson('/api/v1/admin/cms/faq/categories', ['key' => 'support-versions', 'names' => ['nl' => 'Hulp', 'en' => 'Help']])->assertCreated()->json('data.id');
        $doc = fn ($text) => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
        $faq = $this->postJson('/api/v1/admin/cms/faq/items', ['category_id' => $category, 'audience' => 'consumers', 'published' => true,
            'translations' => ['nl' => ['question' => 'Hoe werkt tegoed?', 'answer' => $doc('Oorspronkelijke uitleg.')]]])->assertCreated()->json('data');
        $id = $faq['id'];
        $version = $this->getJson('/api/v1/admin/ai/knowledge/'.$id.'/versions')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $this->putJson('/api/v1/admin/cms/faq/items/'.$id, ['revision' => 1, 'translations' => ['nl' => ['question' => 'Hoe werkt tegoed?', 'answer' => $doc('Bijgewerkte uitleg.')]]])->assertOk();
        $this->postJson('/api/v1/admin/ai/knowledge/'.$id.'/rollback', ['version_id' => $version, 'revision' => 1, 'reason' => 'Stale rollback should fail'])->assertConflict();
        $this->postJson('/api/v1/admin/ai/knowledge/'.$id.'/rollback', ['version_id' => $version, 'revision' => 2, 'reason' => 'Restore approved prior explanation'])->assertOk()->assertJsonPath('data.revision', 3);
        $this->getJson('/api/v1/admin/ai/knowledge/'.$id.'/versions')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.origin', 'rollback');
        $this->assertSame('Oorspronkelijke uitleg.', app(SupportTools::class)->retrieve($admin, 'get_approved_knowledge', ['document_id' => $id], locale: 'nl')->facts['content']);
        $this->assertDatabaseHas('audit_events', ['action' => 'support.knowledge_rolled_back']);
        $this->assertNotNull(app(SupportTools::class)->retrieve($admin, 'get_approved_knowledge', ['document_id' => $id], locale: 'nl')->facts['approved_at']);
        DB::table('support_knowledge_versions')->where('public_id', $version)->update(['checksum' => str_repeat('0', 64)]);
        $this->postJson('/api/v1/admin/ai/knowledge/'.$id.'/rollback', ['version_id' => $version, 'revision' => 3, 'reason' => 'Tampered evidence must fail'])->assertConflict()->assertJsonPath('error.code', 'support_knowledge_integrity_failed');
    }

    public function test_policy_cannot_accept_secrets_arbitrary_semantics_or_unbounded_retries(): void
    {
        $this->asAdmin($this->admin());
        foreach ([['max_retry' => 2], ['timeout_seconds' => 60], ['daily_request_limit' => 1001], ['api_key' => 'secret'],
            ['restrictions' => [['rule_code' => 'SUPPRESS_COMPLAINTS', 'match_type' => 'semantic_claim', 'action' => 'BLOCK', 'enabled' => true]]]] as $change) {
            $this->postJson('/api/v1/admin/platform-settings', ['key' => 'support.ai', 'value' => array_merge($this->policy(), $change), 'reason' => 'Test forbidden setting'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('platform_setting_versions', 0);
        $this->getJson('/api/v1/public/settings')->assertDontSee('support.ai');
    }
}
