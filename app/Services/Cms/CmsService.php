<?php

namespace App\Services\Cms;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Page drafts, publication and history (Trello #5 "Live inline content
 * studio", "Page types, legal content"). Drafts never touch public content;
 * every publication is an immutable revision; restore = new revision.
 */
class CmsService
{
    public const LOCALES = ['nl', 'en'];

    public function __construct(private readonly RichText $richText, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function validateContent(array $content): array
    {
        $data = Validator::make($content, [
            'title' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:70', 'not_regex:/[<>]/'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:160', 'not_regex:/[<>]/'],
            'body' => ['required', 'array'],
        ])->validate();
        if (array_diff(array_keys($content), ['title', 'seo_title', 'seo_description', 'body']) !== []) {
            throw ValidationException::withMessages(['content' => 'Unknown content fields.']);
        }
        $data['body'] = $this->richText->validate($data['body'], 'content.body');

        return $data;
    }

    /**
     * @param  array<string, array{slug: string, content: array<string, mixed>}>  $locales
     */
    public function create(string $key, string $type, string $audience, array $locales, int $actorId): CmsPage
    {
        return DB::transaction(function () use ($key, $type, $audience, $locales, $actorId) {
            $page = CmsPage::create(['key' => $key, 'type' => $type, 'audience' => $audience, 'created_by' => $actorId]);
            foreach ($locales as $locale => $l) {
                CmsPageLocale::create(['page_id' => $page->id, 'locale' => $locale, 'slug' => $l['slug'], 'draft_content' => $this->validateContent($l['content']),
                    'draft_updated_at' => now(), 'draft_updated_by' => $actorId]);
            }
            $this->audit->record('cms.page_created', category: AuditCategory::Admin, resource: $page, actorUserId: $actorId, metadata: ['key' => $key, 'type' => $type]);

            return $page;
        });
    }

    /**
     * Saves (or creates) a locale's draft. Stale revision → 409 (never silently overwrite).
     *
     * @param  array<string, mixed>  $content
     */
    public function saveDraft(CmsPage $page, string $locale, array $content, int $revision, ?string $slug, int $actorId): CmsPageLocale
    {
        $content = $this->validateContent($content);

        return DB::transaction(function () use ($page, $locale, $content, $revision, $slug, $actorId) {
            $l = CmsPageLocale::where('page_id', $page->id)->where('locale', $locale)->lockForUpdate()->first();
            if ($l === null) {
                if ($slug === null) {
                    throw ValidationException::withMessages(['slug' => 'A new translation needs a slug.']);
                }
                $l = CmsPageLocale::create(['page_id' => $page->id, 'locale' => $locale, 'slug' => $slug, 'draft_content' => $content, 'draft_updated_at' => now(), 'draft_updated_by' => $actorId]);
            } else {
                if ($l->draft_revision !== $revision) {
                    throw ApiException::conflict('Someone else saved this page in the meantime. Reload to continue.', 'draft_revision_conflict', ['current_revision' => $l->draft_revision]);
                }
                $l->forceFill(['draft_content' => $content, 'draft_revision' => $l->draft_revision + 1, 'draft_updated_at' => now(), 'draft_updated_by' => $actorId] + ($slug === null ? [] : ['slug' => $slug]))->save();
            }
            $this->audit->record('cms.draft_saved', category: AuditCategory::Admin, resource: $page, actorUserId: $actorId, metadata: ['locale' => $locale]);

            return $l;
        });
    }

    /**
     * @param  array{legal_version?: string|null, effective_date?: string|null, approve?: bool}  $legal
     */
    public function publish(CmsPage $page, string $locale, int $actorId, array $legal = [], ?int $expectedDraftRevision = null): CmsRevision
    {
        if ($page->archived_at !== null) {
            throw ApiException::conflict('Archived pages cannot be published.', 'page_archived');
        }
        $isLegal = in_array($page->type, CmsPage::LEGAL, true);
        if ($isLegal && (empty($legal['legal_version']) || empty($legal['effective_date']) || ($legal['approve'] ?? false) !== true)) {
            throw new ApiException('Legal pages need a version, an effective date and explicit approval to publish.', 422, 'legal_approval_required');
        }

        return DB::transaction(function () use ($page, $locale, $actorId, $legal, $isLegal, $expectedDraftRevision) {
            $l = CmsPageLocale::where('page_id', $page->id)->where('locale', $locale)->lockForUpdate()->first() ?? throw new ApiException('This translation does not exist.', 404, 'not_found');
            if ($l->draft_content === null) {
                throw ApiException::conflict('There is no draft to publish.', 'nothing_to_publish');
            }
            // A scheduled publication only publishes the exact draft that was reviewed.
            if ($expectedDraftRevision !== null && $l->draft_revision !== $expectedDraftRevision) {
                throw ApiException::conflict('The draft changed after it was scheduled.', 'draft_changed');
            }
            if ($isLegal && CmsRevision::where('page_id', $page->id)->where('locale', $locale)->where('legal_version', $legal['legal_version'])->exists()) {
                throw ApiException::conflict('This legal version was already published; use a new version number.', 'legal_version_exists');
            }
            $revision = $this->newRevision($page, $l, $l->draft_content, $actorId, $isLegal ? $legal : [], null);
            $this->audit->record('cms.published', category: AuditCategory::Admin, resource: $page, actorUserId: $actorId,
                metadata: ['locale' => $locale, 'revision' => $revision->number, 'legal_version' => $revision->legal_version]);

            return $revision;
        });
    }

    /** Re-publishes an old revision as a new one; history and legal evidence stay intact. */
    public function restore(CmsPage $page, CmsRevision $from, int $actorId): CmsRevision
    {
        if (in_array($page->type, CmsPage::LEGAL, true)) {
            throw ApiException::conflict('Legal pages are not restored; publish a new version instead.', 'legal_restore_not_allowed');
        }

        return DB::transaction(function () use ($page, $from, $actorId) {
            $l = CmsPageLocale::where('page_id', $page->id)->where('locale', $from->locale)->lockForUpdate()->firstOrFail();
            $l->forceFill(['draft_content' => $from->content, 'draft_revision' => $l->draft_revision + 1, 'draft_updated_at' => now(), 'draft_updated_by' => $actorId])->save();
            $revision = $this->newRevision($page, $l, $from->content, $actorId, [], $from->id);
            $this->audit->record('cms.restored', category: AuditCategory::Admin, resource: $page, actorUserId: $actorId, metadata: ['locale' => $from->locale, 'from' => $from->number, 'as' => $revision->number]);

            return $revision;
        });
    }

    public function archive(CmsPage $page, int $actorId): CmsPage
    {
        if (in_array($page->type, ['terms', 'privacy'], true)) {
            throw ApiException::conflict('The Terms and Privacy Statement cannot be archived.', 'legal_archive_not_allowed');
        }
        $page->forceFill(['archived_at' => now()])->save();
        CmsCache::flush();
        $this->audit->record('cms.archived', category: AuditCategory::Admin, resource: $page, actorUserId: $actorId);

        return $page;
    }

    /**
     * The published version of a legal document type, for registration (B02).
     */
    public static function publishedLegalVersion(string $type): ?string
    {
        return self::publishedLegalRevision($type)?->legal_version;
    }

    /** Canonical NL publication, also used to bind translated links to the same page. */
    public static function publishedLegalRevision(string $type): ?CmsRevision
    {
        return CmsRevision::query()->join('cms_pages', 'cms_pages.id', '=', 'cms_revisions.page_id')->join('cms_page_locales', 'cms_page_locales.published_revision_id', '=', 'cms_revisions.id')
            ->where('cms_pages.type', $type)->whereNull('cms_pages.archived_at')->where('cms_revisions.locale', 'nl')->whereNotNull('cms_revisions.legal_version')
            ->orderByDesc('cms_revisions.id')->first(['cms_revisions.*']);
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array{legal_version?: string|null, effective_date?: string|null}  $legal
     */
    private function newRevision(CmsPage $page, CmsPageLocale $l, array $content, int $actorId, array $legal, ?int $restoredFrom): CmsRevision
    {
        $number = (int) CmsRevision::where('page_id', $page->id)->where('locale', $l->locale)->max('number') + 1;
        $revision = CmsRevision::create(['page_id' => $page->id, 'locale' => $l->locale, 'number' => $number, 'content' => $content,
            'legal_version' => $legal['legal_version'] ?? null, 'effective_date' => isset($legal['effective_date']) ? CarbonImmutable::parse($legal['effective_date'])->format('Y-m-d') : null,
            'approved_by' => isset($legal['legal_version']) ? $actorId : null, 'published_by' => $actorId, 'restored_from_id' => $restoredFrom]);
        $l->forceFill(['published_revision_id' => $revision->id])->save();
        CmsCache::flush();

        return $revision;
    }
}
