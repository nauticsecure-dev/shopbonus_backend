<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use App\Models\CmsSharedBlock;
use App\Models\CmsSharedBlockVersion;
use App\Services\Cms\CmsContentBindings;
use App\Services\Cms\CmsMedia;
use App\Services\Cms\CmsSharedBlocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CmsSharedBlocksController extends Controller
{
    public function index(Request $request, CmsMedia $media): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['after' => ['sometimes', 'string', 'ulid'], 'limit' => ['sometimes', 'integer', 'between:1,50']]);
        $rows = CmsSharedBlock::when(isset($d['after']), fn ($q) => $q->where('public_id', '>', $d['after']))->orderBy('public_id')->limit(($d['limit'] ?? 20) + 1)->get();
        $more = $rows->count() > ($d['limit'] ?? 20);
        $rows = $rows->take($d['limit'] ?? 20);

        return response()->json(['data' => $rows->map(fn ($b) => ['id' => $b->public_id, 'key' => $b->key, 'revision' => $b->revision]), 'next' => $more ? $rows->last()?->public_id : null], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function show(Request $request, string $block, CmsMedia $media, CmsSharedBlocks $blocks): JsonResponse
    {
        $media->authorize($request->user());
        $b = $this->block($block);
        $d = $request->validate(['after' => ['sometimes', 'integer', 'min:0'], 'locale' => ['sometimes', 'in:nl,en,de']]);
        $rows = CmsSharedBlockVersion::where('block_id', $b->id)->where('number', '>', $d['after'] ?? 0)->when(isset($d['locale']), fn ($q) => $q->where('locale', $d['locale']))->orderBy('number')->limit(21)->get();
        $more = $rows->count() > 20;
        $rows = $rows->take(20);

        return response()->json(['data' => ['id' => $b->public_id, 'key' => $b->key, 'revision' => $b->revision, 'versions' => $rows->map(fn ($v) => $blocks->present($v))], 'next' => $more ? $rows->last()?->number : null], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function usages(Request $request, string $block, CmsMedia $media, CmsSharedBlocks $blocks, CmsContentBindings $bindings): JsonResponse
    {
        $media->authorize($request->user());
        $b = $this->block($block);
        $d = $request->validate(['source' => ['required', 'in:draft,revision'], 'after' => ['sometimes', 'integer', 'min:0']]);
        $rows = $d['source'] === 'draft'
            ? CmsPageLocale::where('id', '>', $d['after'] ?? 0)->orderBy('id')->limit(101)->get()
            : CmsRevision::where('id', '>', $d['after'] ?? 0)->orderBy('id')->limit(101)->get();
        $more = $rows->count() > 100;
        $rows = $rows->take(100);
        $out = [];
        foreach ($rows as $row) {
            $page = CmsPage::find($row->page_id);
            if ($page === null) {
                continue;
            }
            $snapshot = $bindings->snapshot($page, $row->locale, $row instanceof CmsRevision ? $row->public_id : 'draft');
            $usages = array_values(array_filter($blocks->usages($snapshot['content']), fn ($usage) => ($usage['source']['block_id'] ?? null) === $b->public_id));
            if ($usages !== []) {
                $out[] = ['source' => $snapshot['source'], 'usages' => $usages];
            }
        }

        return response()->json(['data' => $out, 'scan' => ['source' => $d['source'], 'scanned' => $rows->count(), 'next' => $more ? $rows->last()?->id : null, 'scope' => 'Saved CMS page drafts/revisions only; exhaust both streams. Other sources/frontend usages are unknown.']], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, CmsMedia $media, CmsSharedBlocks $blocks): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['key' => ['required', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9-]*\z/D', 'unique:cms_shared_blocks,key'], 'locale' => ['required', 'in:nl,en,de'], 'node' => ['required', 'array']]);

        return response()->json(['data' => $blocks->present($blocks->version(null, $d['key'], 0, $d['locale'], $d['node'], $request->user()))], 201);
    }

    public function version(Request $request, string $block, CmsMedia $media, CmsSharedBlocks $blocks): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'locale' => ['required', 'in:nl,en,de'], 'node' => ['required', 'array']]);
        $b = $this->block($block);

        return response()->json(['data' => $blocks->present($blocks->version($b, $b->key, $d['revision'], $d['locale'], $d['node'], $request->user()))], 201);
    }

    public function mount(Request $request, string $page, string $locale, CmsMedia $media, CmsSharedBlocks $blocks): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'version_id' => ['required', 'string', 'ulid'], 'index' => ['nullable', 'integer', 'between:0,1999'], 'overrides' => ['sometimes', 'array']]);
        $v = CmsSharedBlockVersion::where('public_id', $d['version_id'])->first() ?? throw new ApiException('Shared version not found.', 404, 'not_found');
        $saved = $blocks->mount($this->page($page), $locale, $d['revision'], $v, $d['index'] ?? null, $d['overrides'] ?? [], $request->user());

        return response()->json(['data' => ['draft_revision' => $saved->draft_revision, 'content' => $saved->draft_content, 'publication' => 'unchanged']]);
    }

    public function detach(Request $request, string $page, string $locale, CmsMedia $media, CmsSharedBlocks $blocks): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'index' => ['required', 'integer', 'between:0,1999']]);
        $saved = $blocks->mount($this->page($page), $locale, $d['revision'], null, $d['index'], [], $request->user());

        return response()->json(['data' => ['draft_revision' => $saved->draft_revision, 'content' => $saved->draft_content, 'publication' => 'unchanged']]);
    }

    private function block(string $id): CmsSharedBlock
    {
        return CmsSharedBlock::where('public_id', $id)->first() ?? throw new ApiException('Shared block not found.', 404, 'not_found');
    }

    private function page(string $id): CmsPage
    {
        return CmsPage::where('public_id', $id)->first() ?? throw new ApiException('Page not found.', 404, 'not_found');
    }
}
