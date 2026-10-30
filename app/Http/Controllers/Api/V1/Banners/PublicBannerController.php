<?php

namespace App\Http\Controllers\Api\V1\Banners;

use App\Enums\UserStatus;
use App\Enums\WorkspacePermission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BannerAsset;
use App\Models\BannerCampaign;
use App\Models\BannerInstallation;
use App\Models\BannerPairing;
use App\Models\User;
use App\Models\WebshopMembership;
use App\Services\Banners\BannerExperiments;
use App\Services\Banners\BannerMeasurement;
use App\Services\Banners\BannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints the embed on the merchant's site calls (Trello #25 §3): published
 * config, events, images, and the editor's pairing exchange and draft access.
 * The public key identifies the shop; the Origin header must be on its
 * allowlist. Nothing here returns drafts without a valid editor token.
 */
class PublicBannerController extends Controller
{
    public function config(Request $request, BannerMeasurement $measurement): JsonResponse
    {
        $installation = $this->installation($request, (string) $request->query('key', ''));
        $installation->forceFill(['last_seen_origin' => $request->header('Origin'), 'last_seen_at' => now()])->saveQuietly();

        $data = $request->validate(['assignments' => ['sometimes', 'array', 'max:50'], 'assignments.*' => ['string', 'max:80']]);
        $body = ['data' => ['campaigns' => $measurement->publishedConfig($installation, $data['assignments'] ?? [])]];
        $etag = '"'.md5((string) json_encode($body)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json($body, 200, ['Cache-Control' => empty($data['assignments']) ? 'public, max-age=60' : 'private, no-store', 'ETag' => $etag, 'Vary' => 'Origin']);
    }

    public function assignments(Request $request, BannerExperiments $experiments): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:40'], 'visitor_key' => ['required', 'uuid'], 'consent' => ['required', 'boolean']]);
        $installation = $this->installation($request, $data['key']);
        if ($data['consent'] && DB::table('webshops')->where('id', $installation->webshop_id)->value('status') !== 'approved') {
            throw new ApiException('Unknown key or origin not allowed.', 403, 'origin_not_allowed');
        }

        if (! $data['consent']) {
            $experiments->revoke($installation, $data['visitor_key']);

            return response()->json(['data' => []], 200, ['Cache-Control' => 'private, no-store']);
        }

        return response()->json(['data' => $experiments->assign($installation, $data['visitor_key'])], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function events(Request $request, BannerMeasurement $measurement): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'events' => ['required', 'array', 'min:1', 'max:'.config('shopbonus.banners.events_per_batch', 50)],
            'events.*.id' => ['required', 'uuid'],
            'events.*.campaign' => ['required', 'string', 'max:40'],
            'events.*.version' => ['required', 'integer', 'min:0'],
            'events.*.type' => ['required', Rule::in(BannerMeasurement::TYPES)],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.device' => ['sometimes', 'nullable', Rule::in(['desktop', 'tablet', 'mobile'])],
            'events.*.path' => ['sometimes', 'nullable', 'string', 'max:500', 'starts_with:/'],
            'events.*.category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'events.*.consent' => ['required', 'boolean'],
            'events.*.assignment_token' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $installation = $this->installation($request, $data['key']);

        return response()->json(['data' => $measurement->ingest($installation, $data['events'], $request->userAgent())], 202);
    }

    public function asset(string $id): Response
    {
        $asset = BannerAsset::where('public_id', $id)->first() ?? throw new ApiException('Image not found.', 404, 'not_found');

        return Storage::disk('local')->response($asset->path, 'banner', ['Content-Type' => $asset->mime, 'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"], 'inline');
    }

    /** Exchange a pairing token, from the paired origin only, for a 30-minute editor token. Single use. */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate(['pairing_token' => ['required', 'string', 'max:64']]);
        $origin = rtrim(strtolower((string) $request->header('Origin', '')), '/');
        $pairing = BannerPairing::where('token_hash', BannerPairing::hashToken($data['pairing_token']))->where('kind', 'pairing')->first();

        if ($pairing === null || $pairing->used_at !== null || $pairing->expires_at->isPast() || $pairing->origin !== $origin) {
            throw new ApiException('This pairing link is not valid any more. Start the editor again from ShopBonus.', 401, 'pairing_invalid');
        }
        $this->assertEditorAccess($pairing);
        if (BannerPairing::whereKey($pairing->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]) !== 1) {
            throw new ApiException('This pairing link was already used.', 401, 'pairing_invalid');
        }

        $token = Str::random(48);
        BannerPairing::create(['token_hash' => BannerPairing::hashToken($token), 'webshop_id' => $pairing->webshop_id, 'user_id' => $pairing->user_id, 'origin' => $pairing->origin,
            'kind' => 'editor', 'expires_at' => now()->addMinutes((int) config('shopbonus.banners.editor_token_ttl_minutes', 30))]);

        return response()->json(['data' => ['editor_token' => $token, 'expires_in' => 60 * (int) config('shopbonus.banners.editor_token_ttl_minutes', 30), 'scope' => 'banner_drafts']]);
    }

    public function editorDraft(Request $request, string $banner): JsonResponse
    {
        $c = $this->editable($request, $banner);

        return response()->json(['data' => ['id' => $c->public_id, 'name' => $c->name, 'draft_revision' => $c->draft_revision, 'draft_config' => $c->draft_config]]);
    }

    public function editorSave(Request $request, string $banner, BannerService $banners): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'config' => ['required', 'array']]);
        $pairing = $this->editorToken($request);
        $c = $banners->saveDraft($this->editable($request, $banner), $data['config'], (int) $data['revision'], $pairing->user_id);

        return response()->json(['data' => ['id' => $c->public_id, 'draft_revision' => $c->draft_revision]]);
    }

    private function installation(Request $request, string $key): BannerInstallation
    {
        $installation = BannerInstallation::where('public_key', $key)->first();
        if ($installation === null || ! $installation->allows($request->header('Origin'))) {
            throw new ApiException('Unknown key or origin not allowed.', 403, 'origin_not_allowed');
        }

        return $installation;
    }

    private function editorToken(Request $request): BannerPairing
    {
        $token = (string) $request->header('X-Banner-Editor-Token', '');
        $pairing = $token === '' ? null : BannerPairing::where('token_hash', BannerPairing::hashToken($token))->where('kind', 'editor')->first();
        if ($pairing === null || $pairing->expires_at->isPast() || $pairing->origin !== rtrim(strtolower((string) $request->header('Origin', '')), '/')) {
            throw new ApiException('Editor session expired or not valid for this site.', 401, 'editor_token_invalid');
        }

        $this->assertEditorAccess($pairing);

        return $pairing;
    }

    private function assertEditorAccess(BannerPairing $pairing): void
    {
        $user = User::find($pairing->user_id);
        $membership = WebshopMembership::where('webshop_id', $pairing->webshop_id)->where('user_id', $pairing->user_id)->first();
        if ($user?->status !== UserStatus::Active || $user->email_verified_at === null || $membership === null || ! $membership->allows(WorkspacePermission::OffersManage)) {
            throw new ApiException('Editor access is no longer authorized.', 401, 'editor_access_revoked');
        }
    }

    private function editable(Request $request, string $banner): BannerCampaign
    {
        $pairing = $this->editorToken($request);

        return BannerCampaign::where('public_id', $banner)->where('webshop_id', $pairing->webshop_id)->first() ?? throw new ApiException('Banner not found.', 404, 'not_found');
    }
}
