<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsPublicationSchedule;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Scheduled publication and archiving (Trello #33–#35). A schedule pins the
 * draft revision that was reviewed: if the draft changes afterwards the
 * schedule becomes stale instead of publishing unreviewed content. At run
 * time the scheduling Super Admin must still be an active Super Admin, and
 * the normal CmsService rules (legal approval, archive limits) apply.
 */
class CmsScheduler
{
    public function __construct(private readonly CmsService $cms, private readonly AuditLogger $audit) {}

    /**
     * @param  array{legal_version?: string|null, effective_date?: string|null, approve?: bool}  $legal
     */
    public function schedule(CmsPage $page, string $action, ?string $locale, ?int $draftRevision, CarbonImmutable $dueAt, User $actor, array $legal = []): CmsPublicationSchedule
    {
        if ($page->archived_at !== null) {
            throw ApiException::conflict('Archived pages cannot be scheduled.', 'page_archived');
        }
        if ($dueAt->lessThanOrEqualTo(now()->addMinute())) {
            throw new ApiException('Pick a time at least one minute from now.', 422, 'validation_failed', ['errors' => ['due_at' => ['Pick a time at least one minute from now.']]]);
        }
        $isLegal = in_array($page->type, CmsPage::LEGAL, true);
        if ($action === 'archive' && in_array($page->type, ['terms', 'privacy'], true)) {
            throw ApiException::conflict('The Terms and Privacy Statement cannot be archived.', 'legal_archive_not_allowed');
        }
        if ($action === 'publish' && $isLegal && (empty($legal['legal_version']) || empty($legal['effective_date']) || ($legal['approve'] ?? false) !== true)) {
            throw new ApiException('Legal pages need a version, an effective date and explicit approval to publish.', 422, 'legal_approval_required');
        }

        return DB::transaction(function () use ($page, $action, $locale, $draftRevision, $dueAt, $actor, $legal, $isLegal) {
            CmsPage::whereKey($page->id)->lockForUpdate()->first();
            if ($action === 'publish') {
                $l = CmsPageLocale::where('page_id', $page->id)->where('locale', $locale)->first() ?? throw new ApiException('This translation does not exist.', 404, 'not_found');
                if ($l->draft_content === null) {
                    throw ApiException::conflict('There is no draft to publish.', 'nothing_to_publish');
                }
                if ($l->draft_revision !== $draftRevision) {
                    throw ApiException::conflict('The draft changed since you reviewed it. Reload to continue.', 'draft_revision_conflict', ['current_revision' => $l->draft_revision]);
                }
            }
            $pending = CmsPublicationSchedule::where('page_id', $page->id)->where('status', 'scheduled')->where('action', $action)
                ->when($action === 'publish', fn ($q) => $q->where('locale', $locale))->exists();
            if ($pending) {
                throw ApiException::conflict('This page already has a pending schedule for that action. Cancel it first.', 'schedule_exists');
            }
            $schedule = CmsPublicationSchedule::create([
                'page_id' => $page->id, 'action' => $action, 'locale' => $action === 'publish' ? $locale : null,
                'draft_revision' => $action === 'publish' ? $draftRevision : null,
                'legal' => $action === 'publish' && $isLegal ? ['legal_version' => $legal['legal_version'], 'effective_date' => $legal['effective_date']] : null,
                'due_at' => $dueAt->utc(), 'scheduled_by' => $actor->id,
            ]);
            $this->audit->record('cms.schedule_created', category: AuditCategory::Admin, resource: $page, actorUserId: $actor->id,
                metadata: ['schedule' => $schedule->public_id, 'action' => $action, 'locale' => $schedule->locale, 'due_at' => $schedule->due_at->toIso8601String()]);

            return $schedule;
        });
    }

    public function cancel(CmsPublicationSchedule $schedule, User $actor): CmsPublicationSchedule
    {
        return DB::transaction(function () use ($schedule, $actor) {
            $s = CmsPublicationSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($s->status !== 'scheduled') {
                throw ApiException::conflict('Only pending schedules can be cancelled.', 'schedule_not_pending', ['status' => $s->status]);
            }
            $s->forceFill(['status' => 'cancelled', 'cancelled_by' => $actor->id, 'processed_at' => now()])->save();
            $this->audit->record('cms.schedule_cancelled', category: AuditCategory::Admin, resource: CmsPage::find($s->page_id), actorUserId: $actor->id, metadata: ['schedule' => $s->public_id]);

            return $s;
        });
    }

    /** Runs every due schedule once; returns how many were processed. */
    public function runDue(int $limit = 50): int
    {
        // A worker that died mid-run leaves "running"; report it instead of retrying blindly.
        CmsPublicationSchedule::where('status', 'running')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'failed', 'outcome_code' => 'interrupted', 'processed_at' => now(), 'updated_at' => now()]);
        $ids = CmsPublicationSchedule::where('status', 'scheduled')->where('due_at', '<=', now())->orderBy('due_at')->limit($limit)->pluck('id');
        $done = 0;
        foreach ($ids as $id) {
            $done += $this->runOne((int) $id) ? 1 : 0;
        }

        return $done;
    }

    private function runOne(int $id): bool
    {
        // Claim first so overlapping workers never act on the same schedule.
        $claimed = CmsPublicationSchedule::whereKey($id)->where('status', 'scheduled')->update(['status' => 'running', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }
        $s = CmsPublicationSchedule::findOrFail($id);
        $page = CmsPage::findOrFail($s->page_id);
        [$status, $code, $revisionId] = $this->execute($s, $page);
        $s->forceFill(['status' => $status, 'outcome_code' => $code, 'revision_id' => $revisionId, 'processed_at' => now()])->save();
        $this->audit->record('cms.schedule_'.($status === 'done' ? 'executed' : $status), outcome: $status === 'done' ? AuditOutcome::Success : AuditOutcome::Failure,
            category: AuditCategory::Admin, resource: $page, actorUserId: $s->scheduled_by, metadata: ['schedule' => $s->public_id, 'action' => $s->action, 'locale' => $s->locale, 'outcome' => $code]);

        return true;
    }

    /** @return array{0: string, 1: string|null, 2: int|null} */
    private function execute(CmsPublicationSchedule $s, CmsPage $page): array
    {
        $actor = User::find($s->scheduled_by);
        if ($actor === null || ! $actor->isActive() || ! $actor->isSuperAdmin()) {
            return ['failed', 'scheduler_not_authorized', null];
        }
        try {
            if ($s->action === 'archive') {
                $this->cms->archive($page, $actor->id);

                return ['done', null, null];
            }
            $legal = $s->legal === null ? [] : $s->legal + ['approve' => true];
            $revision = $this->cms->publish($page, (string) $s->locale, $actor->id, $legal, $s->draft_revision);

            return ['done', null, $revision->id];
        } catch (ApiException $e) {
            return [$e->errorCode === 'draft_changed' ? 'stale' : 'failed', $e->errorCode, null];
        } catch (Throwable) {
            return ['failed', 'unexpected_error', null];
        }
    }
}
