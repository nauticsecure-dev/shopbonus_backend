<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\CmsAiReview;
use App\Models\CmsAiSuggestion;
use App\Models\CmsPageLocale;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Human language/meaning review; structural/model self-review is never independent acceptance. */
class CmsAiReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function review(CmsAiSuggestion $suggestion, User $actor, string $locale, bool $accepted, string $note): CmsAiReview
    {
        return DB::transaction(function () use ($suggestion, $actor, $locale, $accepted, $note) {
            $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if (! $current->isActive() || ! $current->isSuperAdmin() || $actor->id === $suggestion->created_by) {
                throw new ApiException('A different active Super Admin must review language and meaning.', 403, 'independent_review_required');
            }
            $s = CmsAiSuggestion::whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
            if ($s->created_by === $actor->id) {
                throw new ApiException('A different administrator must review this proposal.', 403, 'independent_review_required');
            }
            if ($s->status !== 'proposed' || $s->proposed === null || $s->source_hash === null || $locale !== $s->target_locale || trim($note) === '' || mb_strlen($note) > 2000) {
                throw ApiException::conflict('Review the exact open target-language proposal.', 'cms_review_invalid');
            }
            $source = CmsPageLocale::where('page_id', $s->page_id)->where('locale', $s->source_locale)->first();
            $target = CmsPageLocale::where('page_id', $s->page_id)->where('locale', $s->target_locale)->first();
            if ($source?->draft_revision !== $s->source_revision || CmsSuggestions::sourceHash((array) $source->draft_content) !== $s->source_hash || $target?->draft_revision !== $s->target_revision) {
                throw ApiException::conflict('The source or target changed before review.', 'source_revision_conflict');
            }
            if (CmsAiReview::where('suggestion_id', $s->id)->exists()) {
                throw ApiException::conflict('This proposal already has an immutable independent review.', 'cms_review_exists');
            }
            $r = CmsAiReview::create(['suggestion_id' => $s->id, 'reviewed_by' => $actor->id, 'locale' => $locale, 'accepted' => $accepted, 'note' => $note, 'source_hash' => $s->source_hash, 'proposal_hash' => CmsSuggestions::sourceHash($s->proposed), 'created_at' => now()]);
            if (! $accepted) {
                $s->forceFill(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now()])->save();
            }
            $this->audit->record('cms.suggestion_reviewed', category: AuditCategory::Admin, resource: $s, actorUserId: $actor->id, metadata: ['locale' => $locale, 'accepted' => $accepted, 'proposal_hash' => $r->proposal_hash]);

            return $r;
        }, 3);
    }

    public function assertAccepted(CmsAiSuggestion $s): void
    {
        $r = CmsAiReview::where('suggestion_id', $s->id)->first();
        $reviewer = $r === null ? null : User::find($r->reviewed_by);
        $creator = User::find($s->created_by);
        if ($r === null || ! $r->accepted || $r->reviewed_by === $s->created_by || ! $reviewer?->isActive() || ! $reviewer->isSuperAdmin() || ! $creator?->isActive() || ! $creator->isSuperAdmin()
            || $r->locale !== $s->target_locale || $r->source_hash !== $s->source_hash || $r->proposal_hash !== CmsSuggestions::sourceHash($s->proposed ?? []) || $s->reserved_micros === null) {
            throw ApiException::conflict('An independent current language/meaning review and budget provenance are required.', 'cms_review_required');
        }
    }
}
