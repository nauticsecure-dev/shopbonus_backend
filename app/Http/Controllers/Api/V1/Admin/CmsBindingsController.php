<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CmsAsset;
use App\Models\CmsPage;
use App\Models\CmsPageLocale;
use App\Models\CmsRevision;
use App\Services\Cms\CmsContentBindings;
use App\Services\Cms\CmsMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CmsBindingsController extends Controller
{
    public function fields(Request $request, string $page, string $locale, CmsContentBindings $bindings, CmsMedia $media): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['version' => ['sometimes', 'string', 'max:26'], 'hash' => ['sometimes', 'regex:/\A[a-f0-9]{64}\z/D'], 'after' => ['sometimes', 'integer', 'between:0,100000'], 'limit' => ['sometimes', 'integer', 'between:1,500']]);
        $p = $this->page($page);
        $snapshot = $bindings->snapshot($p, $locale, $d['version'] ?? 'draft');
        if (isset($d['hash']) && ! hash_equals($d['hash'], $snapshot['source']['content_sha256'])) {
            throw ApiException::conflict('Content changed; restart discovery on the current version.', 'content_changed');
        }
        $all = $bindings->fields($p, $snapshot);
        $after = $d['after'] ?? 0;
        $limit = $d['limit'] ?? 100;

        return response()->json(['source' => $snapshot['source'], 'data' => array_slice($all, $after, $limit), 'total' => count($all), 'next' => $after + $limit < count($all) ? $after + $limit : null, 'coverage' => ['backend_source' => 'saved_page_content', 'frontend' => 'unknown', 'other_content_models' => 'not_in_this_inventory']], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $request, string $page, string $locale, CmsContentBindings $bindings, CmsMedia $media): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['version' => ['sometimes', 'string', 'max:26']]);

        return response()->json(['data' => $bindings->preview($bindings->snapshot($this->page($page), $locale, $d['version'] ?? 'draft'))], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function usages(Request $request, string $asset, CmsContentBindings $bindings, CmsMedia $media): JsonResponse
    {
        $media->authorize($request->user());
        $a = CmsAsset::where('public_id', $asset)->first() ?? throw new ApiException('Media not found.', 404, 'not_found');
        $d = $request->validate(['source' => ['required', 'in:draft,revision'], 'after' => ['sometimes', 'integer', 'min:0']]);
        $kind = $d['source'];
        $rows = $kind === 'draft' ? CmsPageLocale::where('id', '>', $d['after'] ?? 0)->orderBy('id')->limit(101)->get() : CmsRevision::where('id', '>', $d['after'] ?? 0)->orderBy('id')->limit(101)->get();
        $more = $rows->count() > 100;
        $rows = $rows->take(100);
        $out = [];
        foreach ($rows as $row) {
            $page = CmsPage::find($row->page_id);
            if ($page === null) {
                continue;
            }
            $version = $row instanceof CmsRevision ? $row->public_id : 'draft';
            $snapshot = $bindings->snapshot($page, $row->locale, $version);
            $content = $snapshot['content'];
            $refs = array_filter($bindings->references($content), fn ($id) => $id === $a->public_id);
            if ($refs === []) {
                continue;
            }
            $out[] = ['source' => $snapshot['source'], 'paths' => array_keys($refs), 'pins' => array_intersect_key($content['media_recipes'] ?? [], $refs)];
        }

        return response()->json(['data' => $out, 'scan' => ['source' => $kind, 'scanned' => $rows->count(), 'next' => $more ? $rows->last()?->id : null, 'scope' => 'Existing CMS page records only; scan both draft and revision streams to exhaustion. Client code, FAQ, other media libraries and tenant assets are not discovered.']], headers: ['Cache-Control' => 'private, no-store']);
    }

    private function page(string $id): CmsPage
    {
        return CmsPage::where('public_id', $id)->first() ?? throw new ApiException('Content not found.', 404, 'not_found');
    }
}
