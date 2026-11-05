<?php

namespace Tests\Feature\Cms;

use App\Enums\PlatformRole;
use App\Exceptions\ApiException;
use App\Jobs\GenerateCmsSuggestion;
use App\Models\CmsAiBulkItem;
use App\Models\CmsAiBulkRun;
use App\Models\CmsAiSuggestion;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\User;
use App\Services\Cms\CmsAiBacklog;
use App\Services\Cms\CmsSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class CmsAiBacklogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        config(['cms.ai_enabled' => true, 'cms.api_key' => 'sk-backlog-synthetic', 'cms.model' => 'gpt-test', 'cms.queue_connection' => 'database', 'cms.daily_limit' => 200]);
        Bus::fake([GenerateCmsSuggestion::class]);
    }

    private function pages(int $count): void
    {
        $pages = [];
        for ($i = 0; $i < $count; $i++) {
            $pages[] = ['public_id' => strtolower((string) Str::ulid()), 'key' => 'bulk-'.Str::lower(Str::random(16)), 'type' => 'info', 'audience' => 'public', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($pages, 200) as $chunk) {
            CmsPage::insert($chunk);
        }
        $locales = [];
        foreach (CmsPage::whereDoesntHave('locales')->get() as $page) {
            $locales[] = ['page_id' => $page->id, 'locale' => 'nl', 'slug' => $page->key, 'draft_content' => json_encode(['title' => 'Welkom', 'body' => ['type' => 'doc', 'content' => []]]), 'draft_revision' => 1];
        }
        foreach (array_chunk($locales, 200) as $chunk) {
            CmsPageLocale::insert($chunk);
        }
    }

    private function plan(): CmsAiBulkRun
    {
        $s = app(CmsAiBacklog::class);
        $run = $s->plan($this->admin, 'translate', 'nl', 'de', 100000);
        while ($run->status === 'planning') {
            $run = $s->advancePlan($run, 200);
        }

        return $run;
    }

    public function test_1001_sources_plan_in_chunks_and_submission_is_bounded_resumable_and_deduplicated(): void
    {
        $this->pages(1001);
        $service = app(CmsAiBacklog::class);
        $run = $service->plan($this->admin, 'translate', 'nl', 'de', 1001);
        $run = $service->advancePlan($run, 200);
        $this->assertSame('planning', $run->status);
        $this->assertDatabaseCount('cms_ai_bulk_items', 200);
        while ($run->status === 'planning') {
            $run = $service->advancePlan($run, 200);
        }
        $this->assertDatabaseCount('cms_ai_bulk_items', 1001);
        $this->assertSame('planned', $run->status);
        $service->advancePlan($run, 200);
        $this->assertDatabaseCount('cms_ai_bulk_items', 1001);
        $service->submit($run, 10);
        Bus::assertDispatchedTimes(GenerateCmsSuggestion::class, 10);
        $this->assertDatabaseCount('cms_ai_suggestions', 10);
        $service->submit($run, 10);
        Bus::assertDispatchedTimes(GenerateCmsSuggestion::class, 20);
        $this->assertSame(20, CmsAiBulkItem::where('run_id', $run->id)->where('status', 'queued')->count());
        $this->assertSame(981, CmsAiBulkItem::where('run_id', $run->id)->where('status', 'pending')->count());
        $this->assertSame(0, CmsPageLocale::whereNotNull('published_revision_id')->count());
    }

    public function test_changed_source_target_and_archived_or_legal_pages_are_not_submitted(): void
    {
        $this->pages(4);
        $run = $this->plan();
        $items = CmsAiBulkItem::orderBy('id')->get();
        CmsPageLocale::where('page_id', $items[0]->page_id)->increment('draft_revision');
        CmsPageLocale::create(['page_id' => $items[1]->page_id, 'locale' => 'de', 'slug' => 'changed-target', 'draft_revision' => 1]);
        CmsPage::whereKey($items[2]->page_id)->update(['archived_at' => now()]);
        CmsPage::whereKey($items[3]->page_id)->update(['type' => 'terms']);
        app(CmsAiBacklog::class)->submit($run, 20);
        $this->assertSame(4, CmsAiBulkItem::where('status', 'stale')->count());
        Bus::assertNotDispatched(GenerateCmsSuggestion::class);
    }

    public function test_daily_request_quota_stops_and_later_resume_does_not_lose_pending_sources(): void
    {
        $this->pages(3);
        $run = $this->plan();
        config(['cms.daily_limit' => 1]);
        $service = app(CmsAiBacklog::class);
        $service->submit($run, 10);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame(1, CmsAiSuggestion::count());
        $this->assertSame(2, CmsAiBulkItem::where('status', 'pending')->count());
        $this->travel(1)->days();
        $service->submit($run, 10);
        $this->assertSame(2, CmsAiSuggestion::count());
        $this->assertSame(1, CmsAiBulkItem::where('status', 'pending')->count());
        Bus::assertDispatchedTimes(GenerateCmsSuggestion::class, 2);
    }

    public function test_reconciliation_rotates_past_waiting_reviews_and_never_calls_apply(): void
    {
        $this->pages(3);
        $run = $this->plan();
        $service = app(CmsAiBacklog::class);
        $service->submit($run, 3);
        CmsAiSuggestion::query()->update(['status' => 'proposed']);
        $service->submit($run, 1);
        $service->submit($run, 1);
        $service->submit($run, 1);
        $this->assertSame('awaiting_review', $run->fresh()->status);
        $this->assertSame(3, CmsAiBulkItem::where('status', 'review_required')->count());
        CmsAiSuggestion::query()->update(['status' => 'rejected']);
        $service->submit($run, 1);
        $service->submit($run, 1);
        $service->submit($run, 1);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(3, CmsAiBulkItem::where('status', 'rejected')->count());
        $this->assertSame(0, CmsPageLocale::whereNotNull('published_revision_id')->count());
    }

    public function test_sync_queue_revoked_creator_and_unauthorized_cli_cannot_submit(): void
    {
        $this->pages(1);
        $run = $this->plan();
        $this->artisan('shopbonus:cms-ai-backlog', ['action' => 'show', '--run' => $run->public_id, '--actor' => User::factory()->create()->id])->assertFailed();
        config(['cms.queue_connection' => 'sync']);
        try {
            app(CmsAiBacklog::class)->submit($run);
            $this->fail('Synchronous bulk execution accepted.');
        } catch (ApiException $e) {
            $this->assertSame('cms_backlog_async_required', $e->errorCode);
        }
        config(['cms.queue_connection' => 'database']);
        $this->admin->platformRoles()->delete();
        $this->expectException(ApiException::class);
        app(CmsAiBacklog::class)->submit($run);
    }

    public function test_console_planning_and_canonical_snapshot_check_preserve_the_reviewed_source(): void
    {
        $this->pages(3);
        $this->artisan('shopbonus:cms-ai-backlog', ['action' => 'plan', '--actor' => $this->admin->id, '--kind' => 'improve', '--source' => 'nl', '--target' => 'nl', '--limit' => 3, '--batch' => 2, '--steps' => 2])->expectsOutputToContain('"pending":3')->assertSuccessful();
        $item = CmsAiBulkItem::orderBy('id')->firstOrFail();
        $source = CmsPageLocale::where('page_id', $item->page_id)->where('locale', 'nl')->sole();
        $source->update(['draft_content' => ['title' => 'Changed without matching snapshot', 'body' => ['type' => 'doc', 'content' => []]]]);
        try {
            app(CmsSuggestions::class)->request(CmsPage::findOrFail($item->page_id), 'improve', 'nl', 'nl', 'snapshot-race', $this->admin, [], ['source_revision' => $item->source_revision, 'source_hash' => $item->source_hash, 'target_revision' => $item->target_revision]);
            $this->fail('A different source was submitted under the planned snapshot.');
        } catch (ApiException $e) {
            $this->assertSame('source_revision_conflict', $e->errorCode);
            $this->assertDatabaseCount('cms_ai_suggestions', 0);
            Bus::assertNotDispatched(GenerateCmsSuggestion::class);
        }
    }
}
