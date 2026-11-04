<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CmsAsset;
use App\Models\CmsMediaRecipe;
use App\Services\Cms\CmsAssetManagement;
use App\Services\Cms\CmsMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CmsMediaController extends Controller
{
    public function index(Request $request, CmsMedia $media): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['after' => ['sometimes', 'string', 'ulid'], 'limit' => ['sometimes', 'integer', 'between:1,50'], 'include_archived' => ['sometimes', 'boolean']]);
        $rows = CmsAsset::when(! ($d['include_archived'] ?? false), fn ($q) => $q->whereNull('archived_at'))->when(isset($d['after']), fn ($q) => $q->where('public_id', '>', $d['after']))->orderBy('public_id')->limit(($d['limit'] ?? 20) + 1)->get();
        $more = $rows->count() > ($d['limit'] ?? 20);
        $rows = $rows->take($d['limit'] ?? 20);

        return response()->json(['data' => $rows->map(fn ($a) => ['id' => $a->public_id, 'archived' => $a->archived_at !== null, 'width' => $a->width, 'height' => $a->height, 'revision' => $a->media_revision, 'original_available' => $a->original_path !== null])->values(), 'next' => $more ? $rows->last()?->public_id : null]);
    }

    public function show(Request $request, string $asset, CmsMedia $media): JsonResponse
    {
        $media->authorize($request->user());

        return response()->json(['data' => $media->present($this->asset($asset))]);
    }

    public function original(Request $request, string $asset, CmsMedia $media): Response
    {
        $media->authorize($request->user());
        $a = $this->asset($asset);
        $bytes = $media->original($a);

        return response($bytes, 200, ['Content-Type' => $a->original_mime, 'Content-Disposition' => 'attachment; filename="original"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"]);
    }

    public function storeRecipe(Request $request, string $asset, CmsMedia $media): JsonResponse
    {
        $d = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'breakpoints' => ['required', 'array']]);
        $a = $media->create($this->asset($asset), $d['revision'], $d['breakpoints'], $request->user());

        return response()->json(['data' => $media->present($a)], 201);
    }

    public function restore(Request $request, string $asset, CmsMedia $media): JsonResponse
    {
        $d = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'recipe' => ['required', 'string', 'ulid']]);
        $a = $this->asset($asset);
        $r = CmsMediaRecipe::where('asset_id', $a->id)->where('public_id', $d['recipe'])->first() ?? throw new ApiException('Recipe not found.', 404, 'not_found');

        return response()->json(['data' => $media->present($media->restore($a, $r, $d['revision'], $request->user()))]);
    }

    public function update(Request $request, string $asset, CmsMedia $media, CmsAssetManagement $management): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'archived' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $media->present($management->state($this->asset($asset), $d['revision'], $d['archived'], $d['reason'], $request->user()))]);
    }

    public function replace(Request $request, string $asset, CmsMedia $media, CmsAssetManagement $management): JsonResponse
    {
        $media->authorize($request->user());
        $d = $request->validate([
            'replacement_asset_id' => ['required', 'string', 'ulid'], 'recipe_id' => ['nullable', 'string', 'ulid'],
            'usages' => ['required', 'array', 'min:1', 'max:50'], 'usages.*' => ['required', 'array:page_id,locale,revision,path'],
            'usages.*.page_id' => ['required', 'string', 'ulid'], 'usages.*.locale' => ['required', 'in:nl,en,de'],
            'usages.*.revision' => ['required', 'integer', 'min:1'], 'usages.*.path' => ['required', 'string', 'max:300'],
        ]);

        return response()->json(['data' => $management->replace($this->asset($asset), $this->asset($d['replacement_asset_id']), $d['recipe_id'] ?? null, $d['usages'], $request->user()), 'scope' => 'selected_drafts_only']);
    }

    private function asset(string $id): CmsAsset
    {
        return CmsAsset::where('public_id', $id)->first() ?? throw new ApiException('Media not found.', 404, 'not_found');
    }
}
