<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CmsAsset;
use App\Models\CmsRevision;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\AboutUsCmsService;
use App\Services\Cms\AboutUsSchema;
use App\Services\Cms\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Super Admin About Us content studio. */
class AboutUsCmsController extends Controller
{
    public function __construct(
        private readonly AboutUsCmsService $aboutUs,
        private readonly CmsService $cms,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json(['data' => $this->aboutUs->adminState($locale)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function saveSection(Request $request, string $sectionKey): JsonResponse
    {
        $locale = $this->locale($request);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'section' => ['required', 'array'],
        ]);
        $page = $this->aboutUs->page();
        $l = $this->aboutUs->saveSection($page, $locale, $sectionKey, $data['section'], (int) $data['revision'], $request->user()->id);

        return response()->json(['data' => [
            'locale' => $l->locale,
            'slug' => $l->slug,
            'draft_revision' => $l->draft_revision,
            'section' => $sectionKey,
        ]]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'content' => ['required', 'array'],
        ]);
        $page = $this->aboutUs->page();
        $l = $this->cms->saveDraft($page, $locale, $data['content'], (int) $data['revision'], null, $request->user()->id);

        return response()->json(['data' => [
            'locale' => $l->locale,
            'slug' => $l->slug,
            'draft_revision' => $l->draft_revision,
        ]]);
    }

    public function preview(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $state = $this->aboutUs->adminState($locale);

        return response()->json([
            'data' => [
                'locale' => $locale,
                'slug' => $state['slug'],
                'content' => $this->aboutUs->resolveAssets($state['draft'] ?? []),
                'preview' => true,
            ],
        ], 200, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function publish(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $page = $this->aboutUs->page();
        $revision = $this->cms->publish($page, $locale, $request->user()->id);

        return response()->json(['data' => $this->revision($revision)]);
    }

    public function versions(Request $request): JsonResponse
    {
        $page = $this->aboutUs->page();
        $locale = $request->validate(['locale' => ['sometimes', Rule::in(CmsService::LOCALES)]])['locale'] ?? null;
        $q = CmsRevision::where('page_id', $page->id)->orderBy('locale')->orderByDesc('number');
        if ($locale !== null) {
            $q->where('locale', $locale);
        }

        return response()->json(['data' => $q->get()->map(fn (CmsRevision $r) => $this->revision($r))->all()]);
    }

    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate(['revision_id' => ['required', 'string', 'max:40']]);
        $page = $this->aboutUs->page();
        $r = CmsRevision::where('public_id', $data['revision_id'])->where('page_id', $page->id)->first()
            ?? throw new ApiException('Revision not found.', 404, 'not_found');

        return response()->json(['data' => $this->revision($this->cms->restore($page, $r, $request->user()->id))]);
    }

    public function schedule(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'publish_at' => ['sometimes', 'nullable', 'date'],
            'unpublish_at' => ['sometimes', 'nullable', 'date', 'after:publish_at'],
        ]);
        $page = $this->aboutUs->page();
        $l = $this->aboutUs->schedule($page, $locale, [
            'publish_at' => $data['publish_at'] ?? null,
            'unpublish_at' => $data['unpublish_at'] ?? null,
        ], (int) $data['revision'], $request->user()->id);

        return response()->json(['data' => [
            'locale' => $l->locale,
            'draft_revision' => $l->draft_revision,
            'schedule' => $l->draft_content['schedule'] ?? null,
        ]]);
    }

    public function aiSuggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['sometimes', Rule::in(CmsService::LOCALES)],
            'section' => ['required', Rule::in(AboutUsSchema::SECTION_KEYS)],
            'focus' => ['sometimes', 'nullable', 'string', 'max:200', 'not_regex:/[<>]/'],
        ]);

        return response()->json([
            'data' => [
                'suggestions' => $this->aboutUs->aiSuggest($data['locale'] ?? 'nl', $data['section'], $data['focus'] ?? null),
                'applied' => false,
            ],
        ]);
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

    private function locale(Request $request): string
    {
        $locale = $request->validate(['locale' => ['sometimes', Rule::in(CmsService::LOCALES)]])['locale'] ?? 'nl';
        if (! in_array($locale, CmsService::LOCALES, true)) {
            throw new ApiException('Unsupported locale.', 404, 'not_found');
        }

        return $locale;
    }

    /** @return array<string, mixed> */
    private function revision(CmsRevision $r): array
    {
        return [
            'id' => $r->public_id,
            'locale' => $r->locale,
            'number' => $r->number,
            'restored_from' => $r->restored_from_id === null ? null : CmsRevision::whereKey($r->restored_from_id)->value('number'),
            'published_by' => DB::table('users')->where('id', $r->published_by)->value('first_name'),
            'published_at' => $r->created_at->toIso8601String(),
        ];
    }
}
