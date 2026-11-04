<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\CmsAiBulkItem;
use App\Models\CmsAiBulkRun;
use App\Models\CmsAiSuggestion;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Durable global saved-page backlog. Submission is not translation review or publication. */
class CmsAiBacklog
{
    public function __construct(private readonly CmsSuggestions $suggestions, private readonly AuditLogger $audit) {}

    public function plan(User $actor, string $kind, string $from, string $to, int $limit): CmsAiBulkRun
    {
        $this->actor($actor->id);
        if (! in_array($kind, CmsAiSuggestion::KINDS, true) || ! in_array($from, CmsService::LOCALES, true) || ! in_array($to, CmsService::LOCALES, true) || (($kind === 'improve') !== ($from === $to)) || $limit < 1 || $limit > 100000) {
            throw new ApiException('Invalid bulk source/target/kind/limit.', 422, 'validation_failed');
        }
        $run = CmsAiBulkRun::create(['created_by' => $actor->id, 'kind' => $kind, 'source_locale' => $from, 'target_locale' => $to, 'source_ceiling' => CmsPageLocale::where('locale', $from)->max('id') ?? 0, 'item_limit' => $limit]);
        $this->audit->record('cms.backlog_planned', category: AuditCategory::Admin, resource: $run, actorUserId: $actor->id, metadata: ['limit' => $limit, 'source_locale' => $from, 'target_locale' => $to, 'kind' => $kind]);

        return $run;
    }

    public function advancePlan(CmsAiBulkRun $run, int $batch = 200): CmsAiBulkRun
    {
        $this->actor($run->created_by);
        $this->batch($batch);

        return DB::transaction(function () use ($run, $batch) {
            $r = CmsAiBulkRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'planning') {
                return $r;
            }
            $rows = CmsPageLocale::where('locale', $r->source_locale)->where('id', '>', $r->source_cursor)->where('id', '<=', $r->source_ceiling)->orderBy('id')->limit($batch)->get();
            $count = CmsAiBulkItem::where('run_id', $r->id)->count();
            foreach ($rows as $source) {
                $r->source_cursor = $source->id;
                $page = CmsPage::find($source->page_id);
                if ($page === null || $page->archived_at !== null || in_array($page->type, CmsPage::LEGAL, true) || $source->draft_content === null || CmsTextBindings::discover($source->draft_content, $page->type, $page->key) === []) {
                    continue;
                }
                if ($count >= $r->item_limit) {
                    break;
                }
                $target = CmsPageLocale::where('page_id', $page->id)->where('locale', $r->target_locale)->first();
                CmsAiBulkItem::create(['run_id' => $r->id, 'page_id' => $page->id, 'source_revision' => $source->draft_revision, 'source_hash' => CmsSuggestions::sourceHash($source->draft_content), 'target_revision' => $target?->draft_revision]);
                $count++;
            }
            if ($rows->isEmpty() || $r->source_cursor >= $r->source_ceiling || $count >= $r->item_limit) {
                $r->status = 'planned';
            }
            $r->save();

            return $r;
        }, 3);
    }

    public function submit(CmsAiBulkRun $run, int $batch = 50): CmsAiBulkRun
    {
        $this->batch($batch);
        $actor = $this->actor($run->created_by);
        $connection = (string) config('cms.queue_connection');
        if (config('queue.connections.'.$connection.'.driver') === 'sync' || ! is_array(config('queue.connections.'.$connection))) {
            throw ApiException::conflict('Bulk submission requires a configured asynchronous queue.', 'cms_backlog_async_required');
        }
        $lock = Cache::lock('cms-backlog:'.$run->public_id, 300);
        if (! $lock->get()) {
            throw ApiException::conflict('This backlog already has an active submitter.', 'cms_backlog_busy');
        }
        try {
            $run->refresh();
            if ($run->status === 'planning') {
                throw ApiException::conflict('Finish planning before submission.', 'cms_backlog_planning');
            }
            $this->reconcile($run, $batch);
            $deadline = microtime(true) + 20;
            foreach (CmsAiBulkItem::where('run_id', $run->id)->where('status', 'pending')->orderBy('id')->limit($batch)->get() as $item) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $actor = $this->actor($run->created_by);
                $p = CmsPage::find($item->page_id);
                $source = CmsPageLocale::where('page_id', $item->page_id)->where('locale', $run->source_locale)->first();
                $target = CmsPageLocale::where('page_id', $item->page_id)->where('locale', $run->target_locale)->first();
                if ($p === null || $p->archived_at !== null || in_array($p->type, CmsPage::LEGAL, true) || $source?->draft_revision !== $item->source_revision || CmsSuggestions::sourceHash((array) $source->draft_content) !== $item->source_hash || $target?->draft_revision !== $item->target_revision) {
                    $item->update(['status' => 'stale', 'error_code' => 'source_or_target_changed']);

                    continue;
                }
                try {
                    $s = $this->suggestions->request($p, $run->kind, $run->source_locale, $run->target_locale, 'bulk-'.$run->public_id.'-'.$item->id, $actor, [], ['source_revision' => $item->source_revision, 'source_hash' => $item->source_hash, 'target_revision' => $item->target_revision]);
                    $item->update(['suggestion_id' => $s->id, 'status' => $this->state($s), 'error_code' => $s->error_code]);
                    if ($s->status === 'failed') {
                        break;
                    }
                } catch (ApiException $e) {
                    if ($e->errorCode === 'source_revision_conflict') {
                        $item->update(['status' => 'stale', 'error_code' => 'source_or_target_changed']);

                        continue;
                    }
                    // Reconciliation can find a terminal synchronous failure; never mint another attempt key.
                    $s = CmsAiSuggestion::where('created_by', $actor->id)->where('request_key', 'bulk-'.$run->public_id.'-'.$item->id)->first();
                    if ($s !== null) {
                        $item->update(['suggestion_id' => $s->id, 'status' => $this->state($s), 'error_code' => $s->error_code]);
                    } else {
                        $item->update(['error_code' => $e->errorCode]);
                    }
                    break;
                }
            }
            $run->forceFill(['status' => $this->summaryStatus($run)])->save();
            $this->audit->record('cms.backlog_submitted', category: AuditCategory::Admin, resource: $run, actorUserId: $actor->id, metadata: ['status' => $run->status, 'counts' => CmsAiBulkItem::where('run_id', $run->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all()]);

            return $run;
        } finally {
            $lock->release();
        }
    }

    private function reconcile(CmsAiBulkRun $run, int $batch): void
    {
        $query = fn () => CmsAiBulkItem::where('run_id', $run->id)->whereIn('status', ['queued', 'review_required'])->orderBy('id');
        $rows = $query()->where('id', '>', $run->reconcile_cursor)->limit($batch)->get();
        if ($rows->isEmpty() && $run->reconcile_cursor > 0) {
            $rows = $query()->limit($batch)->get();
        }
        foreach ($rows as $item) {
            $s = CmsAiSuggestion::find($item->suggestion_id);
            if ($s !== null) {
                $item->update(['status' => $this->state($s), 'error_code' => $s->error_code]);
            }
            $run->reconcile_cursor = $item->id;
        }
        $run->save();
    }

    private function state(CmsAiSuggestion $s): string
    {
        return match ($s->status) {
            'queued','processing' => 'queued','proposed' => 'review_required','applied','rejected' => $s->status,default => 'failed'
        };
    }

    private function summaryStatus(CmsAiBulkRun $run): string
    {
        $states = CmsAiBulkItem::where('run_id', $run->id)->distinct()->pluck('status');
        if ($states->isEmpty()) {
            return 'empty';
        }
        if ($states->contains('pending')) {
            if (CmsAiBulkItem::where('run_id', $run->id)->where('status', 'pending')->whereNotNull('error_code')->exists()) {
                return 'blocked';
            }

            return 'planned';
        }
        if ($states->contains('queued')) {
            return 'queued';
        }
        if ($states->contains('review_required')) {
            return 'awaiting_review';
        }

        return $states->contains('failed') || $states->contains('stale') ? 'needs_attention' : 'completed';
    }

    private function actor(int $id): User
    {
        $u = User::find($id);
        if (! $u?->isActive() || ! $u->isSuperAdmin()) {
            throw new ApiException('Current active Super Admin authority required.', 403, 'forbidden');
        }

        return $u;
    }

    private function batch(int $size): void
    {
        if ($size < 1 || $size > 200) {
            throw new ApiException('Use a batch size from1to200.', 422, 'validation_failed');
        }
    }
}
