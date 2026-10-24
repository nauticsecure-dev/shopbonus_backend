<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BannerAsset;
use App\Models\BannerCampaign;
use App\Models\BannerPairing;
use App\Models\BannerVersion;
use App\Models\Offer;
use App\Services\Audit\AuditLogger;
use App\Services\Banners\BannerMeasurement;
use App\Services\Banners\BannerService;
use App\Services\Banners\TriggerEngine;
use App\Services\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Merchant banner campaigns (Trello #25): drafts, versions, lifecycle,
 * simulation, results, installation, editor pairing and images. Every
 * campaign is resolved inside the current workspace.
 */
class BannerController extends Controller
{
    public function __construct(private readonly BannerService $banners, private readonly BannerMeasurement $measurement, private readonly AuditLogger $audit) {}

    public function index(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['draft', 'scheduled', 'live', 'paused'])], 'search' => ['sometimes', 'string', 'max:100'],
            'placement' => ['sometimes', 'string', 'max:20']]);
        $rows = BannerCampaign::where('webshop_id', $current->webshop()->id)
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(isset($data['search']), fn ($q) => $q->where('name', 'like', '%'.addcslashes($data['search'], '%_\\').'%'))
            ->orderByDesc('updated_at')->limit(200)->get()
            ->filter(fn (BannerCampaign $c) => ! isset($data['placement']) || ($c->draft_config['placement']['anchor'] ?? null) === $data['placement']);
        $from = CarbonImmutable::now((string) config('shopbonus.business_timezone'))->subDays(29);
        $to = CarbonImmutable::now((string) config('shopbonus.business_timezone'));

        return response()->json(['data' => $rows->map(fn (BannerCampaign $c) => $this->present($c) + ['stats_30d' => array_intersect_key(
            $this->measurement->results($c, $from, $to, null), array_flip(['viewable_impressions', 'cta_clicks', 'ctr', 'attributed_orders']))])->values()->all()]);
    }

    public function store(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'not_regex:/[<>]/'], 'offer_id' => ['sometimes', 'nullable', 'string', 'max:40'], 'config' => ['required', 'array']]);
        $offer = isset($data['offer_id']) ? (Offer::where('public_id', $data['offer_id'])->where('webshop_id', $current->webshop()->id)->first()
            ?? throw new ApiException('Offer not found.', 404, 'not_found')) : null;

        return response()->json(['data' => $this->present($this->banners->create($current->webshop(), $data['name'], $offer, $data['config'], $current->membership()->user_id))], 201);
    }

    public function show(CurrentWorkspace $current, string $banner): JsonResponse
    {
        $c = $this->find($current, $banner);

        return response()->json(['data' => $this->present($c) + ['draft_config' => $c->draft_config,
            'published_config' => $c->published_version_id === null ? null : BannerVersion::find($c->published_version_id)?->config,
            'notice' => 'Customers see the published version until you publish the draft.']]);
    }

    public function saveDraft(Request $request, CurrentWorkspace $current, string $banner): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'config' => ['required', 'array'], 'name' => ['sometimes', 'string', 'max:120', 'not_regex:/[<>]/']]);
        $c = $this->banners->saveDraft($this->find($current, $banner), $data['config'], (int) $data['revision'], $current->membership()->user_id, $data['name'] ?? null);

        return response()->json(['data' => $this->present($c)]);
    }

    public function duplicate(CurrentWorkspace $current, string $banner): JsonResponse
    {
        return response()->json(['data' => $this->present($this->banners->duplicate($this->find($current, $banner), $current->membership()->user_id))], 201);
    }

    public function publish(CurrentWorkspace $current, string $banner): JsonResponse
    {
        return response()->json(['data' => $this->present($this->banners->publish($this->find($current, $banner), $current->membership()->user_id))]);
    }

    public function schedule(Request $request, CurrentWorkspace $current, string $banner): JsonResponse
    {
        $data = $request->validate(['publish_at' => ['required', 'date_format:Y-m-d\TH:i']]);
        $at = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['publish_at'], (string) config('shopbonus.business_timezone')) ?: throw new ApiException('Invalid time.', 422, 'validation_failed');

        return response()->json(['data' => $this->present($this->banners->schedule($this->find($current, $banner), $at, $current->membership()->user_id))]);
    }

    public function pause(CurrentWorkspace $current, string $banner): JsonResponse
    {
        return response()->json(['data' => $this->present($this->banners->pause($this->find($current, $banner), $current->membership()->user_id))]);
    }

    public function resume(CurrentWorkspace $current, string $banner): JsonResponse
    {
        return response()->json(['data' => $this->present($this->banners->resume($this->find($current, $banner), $current->membership()->user_id))]);
    }

    public function rollback(Request $request, CurrentWorkspace $current, string $banner): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->present($this->banners->rollback($this->find($current, $banner), (int) $data['version'], $current->membership()->user_id))]);
    }

    public function versions(CurrentWorkspace $current, string $banner): JsonResponse
    {
        $c = $this->find($current, $banner);
        $live = BannerVersion::find($c->published_version_id)?->version;

        return response()->json(['data' => BannerVersion::where('campaign_id', $c->id)->orderByDesc('version')->get()->map(fn (BannerVersion $v) => [
            'version' => $v->version, 'live' => $v->version === $live, 'published_at' => $v->created_at->toIso8601String(),
            'published_by' => $v->published_by === null ? 'schedule' : DB::table('users')->where('id', $v->published_by)->value('first_name'),
        ])->all()]);
    }

    public function simulate(Request $request, CurrentWorkspace $current, string $banner, TriggerEngine $engine): JsonResponse
    {
        $ctx = $request->validate([
            'path' => ['required', 'string', 'max:255', 'starts_with:/'], 'category' => ['sometimes', 'nullable', 'string', 'max:60'], 'product_sku' => ['sometimes', 'nullable', 'string', 'max:60'],
            'seconds' => ['sometimes', 'integer', 'min:0'], 'scroll_pct' => ['sometimes', 'integer', 'between:0,100'], 'exit_intent' => ['sometimes', 'boolean'],
            'device' => ['sometimes', Rule::in(['desktop', 'tablet', 'mobile'])], 'language' => ['sometimes', 'string', 'max:5'], 'local_time' => ['sometimes', 'date'],
            'visitor' => ['sometimes', Rule::in(['first', 'returning'])], 'shown_this_session' => ['sometimes', 'integer', 'min:0'], 'last_shown_at' => ['sometimes', 'date'],
            'dismissed' => ['sometimes', 'boolean'], 'cart_total_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        $c = $this->find($current, $banner);
        $offer = $c->offer_id === null ? null : Offer::with('currentVersion')->find($c->offer_id);

        return response()->json(['data' => $engine->evaluate($c->draft_config, $ctx + ['simulated' => true], $offer?->currentVersion?->min_order_minor)]);
    }

    public function results(Request $request, CurrentWorkspace $current, string $banner): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'], 'device' => ['sometimes', Rule::in(['desktop', 'tablet', 'mobile', 'unknown'])]]);
        $tz = (string) config('shopbonus.business_timezone');

        return response()->json(['data' => $this->measurement->results($this->find($current, $banner),
            CarbonImmutable::parse($data['from'] ?? CarbonImmutable::now($tz)->subDays(29)->format('Y-m-d'), $tz), CarbonImmutable::parse($data['to'] ?? CarbonImmutable::now($tz)->format('Y-m-d'), $tz), $data['device'] ?? null)]);
    }

    public function installation(CurrentWorkspace $current): JsonResponse
    {
        $i = $this->banners->installation($current->webshop());
        $live = BannerCampaign::where('webshop_id', $current->webshop()->id)->where('status', 'live')->count();

        return response()->json(['data' => ['public_key' => $i->public_key, 'allowed_origins' => $i->allowed_origins,
            'status' => $i->last_seen_at === null ? 'not_seen' : ($i->last_seen_at->greaterThan(now()->subDay()) ? 'installed' : 'stale'),
            'last_seen' => $i->last_seen_at === null ? null : ['origin' => $i->last_seen_origin, 'at' => $i->last_seen_at->toIso8601String()],
            'live_campaigns' => $live,
            'snippet' => '<script async src="'.rtrim((string) config('app.url'), '/').'/embed/v1.js" data-shopbonus-key="'.$i->public_key.'"></script>']]);
    }

    public function updateInstallation(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['origins' => ['required', 'array', 'min:1', 'max:10'], 'origins.*' => ['string', 'max:255']]);
        $this->banners->setOrigins($current->webshop(), $data['origins'], $current->membership()->user_id);

        return $this->installation($current);
    }

    public function pairing(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['origin' => ['required', 'string', 'max:255']]);
        $installation = $this->banners->installation($current->webshop());
        if (! $installation->allows($data['origin'])) {
            throw new ApiException('This origin is not allowed for your webshop.', 422, 'origin_not_allowed');
        }
        $token = Str::random(40);
        BannerPairing::create(['token_hash' => BannerPairing::hashToken($token), 'webshop_id' => $current->webshop()->id, 'user_id' => $current->membership()->user_id,
            'origin' => rtrim(strtolower($data['origin']), '/'), 'kind' => 'pairing', 'expires_at' => now()->addMinutes((int) config('shopbonus.banners.pairing_ttl_minutes', 10))]);
        $this->audit->record('banner.pairing_issued', category: AuditCategory::Security, webshopId: $current->webshop()->id, actorUserId: $current->membership()->user_id, metadata: ['origin' => $data['origin']]);

        return response()->json(['data' => ['pairing_token' => $token, 'expires_in' => 60 * (int) config('shopbonus.banners.pairing_ttl_minutes', 10), 'origin' => $data['origin']]], 201);
    }

    public function uploadAsset(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048', 'dimensions:min_width=16,min_height=16,max_width=4000,max_height=4000']]);
        $file = $request->file('image');
        $size = getimagesize($file->getRealPath()) ?: [0, 0];
        $path = 'banners/'.$current->webshop()->public_id.'/'.Str::lower((string) Str::ulid()).'.'.$file->guessExtension();
        if (Storage::disk('local')->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new ApiException('The image could not be stored. Please try again.', 503, 'storage_unavailable');
        }
        $asset = BannerAsset::create(['webshop_id' => $current->webshop()->id, 'path' => $path, 'mime' => (string) $file->getMimeType(), 'width' => $size[0], 'height' => $size[1], 'uploaded_by' => $current->membership()->user_id]);

        return response()->json(['data' => ['id' => $asset->public_id, 'width' => $asset->width, 'height' => $asset->height, 'url' => route('api.v1.banners.assets.show', ['id' => $asset->public_id])]], 201);
    }

    /** @return array<string, mixed> */
    private function present(BannerCampaign $c): array
    {
        return ['id' => $c->public_id, 'name' => $c->name, 'status' => $c->status, 'template' => $c->draft_config['template'] ?? null, 'placement' => $c->draft_config['placement']['anchor'] ?? null,
            'offer_id' => $c->offer_id === null ? null : Offer::whereKey($c->offer_id)->value('public_id'), 'draft_revision' => $c->draft_revision,
            'live_version' => $c->published_version_id === null ? null : BannerVersion::whereKey($c->published_version_id)->value('version'),
            'scheduled_at' => $c->scheduled_at?->toIso8601String(), 'updated_at' => $c->updated_at->toIso8601String()];
    }

    private function find(CurrentWorkspace $current, string $id): BannerCampaign
    {
        return BannerCampaign::where('public_id', $id)->where('webshop_id', $current->webshop()->id)->first() ?? throw new ApiException('Banner not found.', 404, 'not_found');
    }
}
