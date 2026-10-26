<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CmsAsset;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\CmsCache;
use App\Services\Cms\CmsService;
use App\Services\Cms\RichText;
use App\Services\Support\SupportKnowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Super Admin content studio (Trello #5): pages, translations, revisions,
 * legal publication, FAQ and images. Mutations need step-up (route group).
 */
class CmsController extends Controller
{
    public function __construct(private readonly CmsService $cms, private readonly AuditLogger $audit) {}

    // ---------------------------------------------------------------- pages

    public function pages(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['sometimes', Rule::in(CmsPage::TYPES)], 'search' => ['sometimes', 'string', 'max:100']]);
        $pages = CmsPage::with('locales')->when(isset($data['type']), fn ($q) => $q->where('type', $data['type']))
            ->when(isset($data['search']), fn ($q) => $q->where(fn ($q) => $q->where('key', 'like', '%'.addcslashes($data['search'], '%_\\').'%')
                ->orWhereHas('locales', fn ($l) => $l->where('slug', 'like', '%'.addcslashes($data['search'], '%_\\').'%'))))
            ->orderBy('key')->get();

        return response()->json(['data' => $pages->map(fn (CmsPage $p) => $this->present($p))->all()]);
    }

    public function storePage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/', Rule::unique('cms_pages', 'key')],
            'type' => ['required', Rule::in(CmsPage::TYPES)],
            'audience' => ['sometimes', Rule::in(FaqItem::AUDIENCES)],
            'locales' => ['required', 'array:'.implode(',', CmsService::LOCALES), 'min:1'],
            'locales.*.slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'locales.*.content' => ['required', 'array'],
        ]);
        foreach ($data['locales'] as $locale => $l) {
            if (CmsPageLocale::where('locale', $locale)->where('slug', $l['slug'])->exists()) {
                throw ValidationException::withMessages(["locales.{$locale}.slug" => 'This address is already used.']);
            }
        }
        $page = $this->cms->create($data['key'], $data['type'], $data['audience'] ?? 'public', $data['locales'], $request->user()->id);

        return response()->json(['data' => $this->present($page->load('locales'))], 201);
    }

    public function showPage(string $page): JsonResponse
    {
        $p = $this->find($page)->load('locales');

        return response()->json(['data' => $this->present($p) + ['locales_detail' => $p->locales->mapWithKeys(fn (CmsPageLocale $l) => [$l->locale => [
            'slug' => $l->slug, 'draft_revision' => $l->draft_revision, 'draft' => $l->draft_content,
            'published' => $l->published_revision_id === null ? null : CmsRevision::find($l->published_revision_id)?->content,
        ]])->all()]]);
    }

    public function saveDraft(Request $request, string $page, string $locale): JsonResponse
    {
        $this->locale($locale);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'content' => ['required', 'array'], 'slug' => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/']]);
        $p = $this->find($page);
        if (isset($data['slug']) && CmsPageLocale::where('locale', $locale)->where('slug', $data['slug'])->where('page_id', '!=', $p->id)->exists()) {
            throw ValidationException::withMessages(['slug' => 'This address is already used.']);
        }
        $l = $this->cms->saveDraft($p, $locale, $data['content'], (int) $data['revision'], $data['slug'] ?? null, $request->user()->id);

        return response()->json(['data' => ['locale' => $l->locale, 'slug' => $l->slug, 'draft_revision' => $l->draft_revision]]);
    }

    /** Admin-only preview of a draft: never cached, never indexed. */
    public function preview(string $page, string $locale): JsonResponse
    {
        $this->locale($locale);
        $l = CmsPageLocale::where('page_id', $this->find($page)->id)->where('locale', $locale)->first() ?? throw new ApiException('This translation does not exist.', 404, 'not_found');

        return response()->json(['data' => ['locale' => $locale, 'slug' => $l->slug, 'content' => $l->draft_content, 'preview' => true]], 200,
            ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function publish(Request $request, string $page, string $locale): JsonResponse
    {
        $this->locale($locale);
        $data = $request->validate(['legal_version' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'], 'effective_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'approve' => ['sometimes', 'boolean']]);
        $revision = $this->cms->publish($this->find($page), $locale, $request->user()->id, $data);

        return response()->json(['data' => $this->revision($revision)]);
    }

    public function revisions(string $page): JsonResponse
    {
        $p = $this->find($page);

        return response()->json(['data' => CmsRevision::where('page_id', $p->id)->orderBy('locale')->orderByDesc('number')->get()->map(fn (CmsRevision $r) => $this->revision($r))->all()]);
    }

    public function restore(Request $request, string $page, string $revision): JsonResponse
    {
        $p = $this->find($page);
        $r = CmsRevision::where('public_id', $revision)->where('page_id', $p->id)->first() ?? throw new ApiException('Revision not found.', 404, 'not_found');

        return response()->json(['data' => $this->revision($this->cms->restore($p, $r, $request->user()->id))]);
    }

    public function archive(Request $request, string $page): JsonResponse
    {
        return response()->json(['data' => $this->present($this->cms->archive($this->find($page), $request->user()->id)->load('locales'))]);
    }

    /** Missing or stale translations (Trello #5 "expose missing/stale translations"). */
    public function translations(): JsonResponse
    {
        $rows = [];
        foreach (CmsPage::with('locales')->whereNull('archived_at')->orderBy('key')->get() as $p) {
            foreach (CmsService::LOCALES as $locale) {
                $l = $p->locales->firstWhere('locale', $locale);
                $published = $l?->published_revision_id === null ? null : CmsRevision::find($l->published_revision_id);
                $status = match (true) {
                    $l === null => 'missing',
                    $published === null => 'unpublished',
                    $l->draft_content !== $published->content => 'draft_differs',
                    default => 'published',
                };
                $rows[] = ['page' => $p->key, 'locale' => $locale, 'status' => $status];
            }
        }

        return response()->json(['data' => $rows]);
    }

    public function uploadAsset(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:4096', 'dimensions:min_width=16,min_height=16,max_width=6000,max_height=6000']]);
        $file = $request->file('image');
        $size = getimagesize($file->getRealPath()) ?: [0, 0];
        $path = 'cms/'.Str::lower((string) Str::ulid()).'.'.$file->guessExtension();
        if (Storage::disk('local')->putFileAs('cms', $file, basename($path)) === false) {
            throw new ApiException('The image could not be stored. Please try again.', 503, 'storage_unavailable');
        }
        $asset = CmsAsset::create(['path' => $path, 'mime' => (string) $file->getMimeType(), 'width' => $size[0], 'height' => $size[1], 'uploaded_by' => $request->user()->id]);
        $this->audit->record('cms.asset_uploaded', category: AuditCategory::Admin, resource: $asset, actorUserId: $request->user()->id);

        return response()->json(['data' => ['id' => $asset->public_id, 'width' => $asset->width, 'height' => $asset->height, 'url' => route('api.v1.public.content-assets.show', ['id' => $asset->public_id])]], 201);
    }

    // ---------------------------------------------------------------- FAQ

    public function faq(): JsonResponse
    {
        return response()->json(['data' => FaqCategory::orderBy('position')->get()->map(fn (FaqCategory $c) => ['id' => $c->public_id, 'key' => $c->key, 'names' => $c->names, 'position' => $c->position,
            'items' => FaqItem::where('category_id', $c->id)->orderBy('position')->get()->map(fn (FaqItem $i) => $this->faqItem($i))->all()])->all()]);
    }

    public function storeFaqCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/', Rule::unique('faq_categories', 'key')], 'names' => ['required', 'array:nl,en'],
            'names.nl' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'], 'names.en' => ['required', 'string', 'max:80', 'not_regex:/[<>]/']]);
        $c = FaqCategory::create($data + ['position' => (int) FaqCategory::max('position') + 1]);
        CmsCache::flush();

        return response()->json(['data' => ['id' => $c->public_id, 'key' => $c->key, 'names' => $c->names, 'position' => $c->position]], 201);
    }

    public function storeFaqItem(Request $request, RichText $rich): JsonResponse
    {
        $data = $this->validateFaq($request, $rich, creating: true);
        $category = FaqCategory::where('public_id', $data['category_id'])->first() ?? throw new ApiException('Category not found.', 404, 'not_found');
        $item = app(SupportKnowledge::class)->create($category, $data, $request->user());

        return response()->json(['data' => $this->faqItem($item)], 201);
    }

    public function updateFaqItem(Request $request, string $item, RichText $rich): JsonResponse
    {
        $data = $this->validateFaq($request, $rich, creating: false);
        $model = FaqItem::where('public_id', strtolower($item))->first() ?? throw new ApiException('FAQ item not found.', 404, 'not_found');
        $model = app(SupportKnowledge::class)->update($model, $data, $data['revision'], $request->user());

        return response()->json(['data' => $this->faqItem($model)]);
    }

    public function reorderFaq(Request $request): JsonResponse
    {
        $data = $request->validate(['category_id' => ['required', 'string', 'max:40'], 'items' => ['required', 'array', 'min:1'], 'items.*' => ['string', 'max:40']]);
        $category = FaqCategory::where('public_id', $data['category_id'])->first() ?? throw new ApiException('Category not found.', 404, 'not_found');
        $ids = array_values(array_unique($data['items']));
        // Membership is checked up front: MySQL reports 0 affected rows for an unchanged position.
        if (FaqItem::whereIn('public_id', $ids)->where('category_id', $category->id)->count() !== count($ids)) {
            throw new ApiException('Every item must belong to this category.', 422, 'validation_failed');
        }
        DB::transaction(function () use ($ids, $category) {
            foreach ($ids as $i => $id) {
                FaqItem::where('public_id', $id)->where('category_id', $category->id)->update(['position' => $i + 1, 'updated_at' => now()]);
            }
        });
        CmsCache::flush();

        return $this->faq();
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function validateFaq(Request $request, RichText $rich, bool $creating): array
    {
        $data = $request->validate([
            'category_id' => [$creating ? 'required' : 'prohibited', 'string', 'max:40'],
            'revision' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
            'audience' => ['sometimes', Rule::in(FaqItem::AUDIENCES)],
            'published' => ['sometimes', 'boolean'],
            'translations' => [$creating ? 'required' : 'sometimes', 'array:nl,en', 'min:1'],
            'translations.*.question' => ['required', 'string', 'max:200', 'not_regex:/[<>]/'],
            'translations.*.answer' => ['required', 'array'],
        ]);
        foreach ($data['translations'] ?? [] as $locale => $t) {
            $data['translations'][$locale]['answer'] = $rich->validate($t['answer'], "translations.{$locale}.answer");
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function faqItem(FaqItem $i): array
    {
        return ['id' => $i->public_id, 'audience' => $i->audience, 'published' => $i->published, 'position' => $i->position, 'revision' => $i->revision, 'translations' => $i->translations];
    }

    /** @return array<string, mixed> */
    private function present(CmsPage $p): array
    {
        return ['id' => $p->public_id, 'key' => $p->key, 'type' => $p->type, 'audience' => $p->audience, 'archived' => $p->archived_at !== null,
            'locales' => $p->locales->mapWithKeys(fn (CmsPageLocale $l) => [$l->locale => ['slug' => $l->slug, 'published' => $l->published_revision_id !== null,
                'draft_revision' => $l->draft_revision, 'draft_updated_at' => $l->draft_updated_at?->toIso8601String()]])->all()];
    }

    /** @return array<string, mixed> */
    private function revision(CmsRevision $r): array
    {
        return ['id' => $r->public_id, 'locale' => $r->locale, 'number' => $r->number, 'legal_version' => $r->legal_version, 'effective_date' => $r->effective_date?->format('Y-m-d'),
            'approved_by' => $r->approved_by === null ? null : DB::table('users')->where('id', $r->approved_by)->value('first_name'),
            'published_by' => DB::table('users')->where('id', $r->published_by)->value('first_name'),
            'restored_from' => $r->restored_from_id === null ? null : CmsRevision::whereKey($r->restored_from_id)->value('number'), 'published_at' => $r->created_at->toIso8601String()];
    }

    private function find(string $id): CmsPage
    {
        return CmsPage::where('public_id', $id)->first() ?? throw new ApiException('Page not found.', 404, 'not_found');
    }

    private function locale(string $locale): void
    {
        if (! in_array($locale, CmsService::LOCALES, true)) {
            throw new ApiException('Unsupported locale.', 404, 'not_found');
        }
    }
}
