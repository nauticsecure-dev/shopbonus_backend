<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BannerAiRequest;
use App\Models\BannerAiResult;
use App\Models\BannerAiReview;
use App\Models\BannerCampaign;
use App\Services\Banners\BannerAi;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BannerAiController extends Controller
{
    public function __construct(private readonly BannerAi $ai) {}

    public function index(CurrentWorkspace $current, string $banner): JsonResponse
    {
        $campaign = $this->campaign($current, $banner);
        $rows = BannerAiRequest::where('campaign_id', $campaign->id)
            ->when(! $this->ai->allowed($campaign->webshop_id, $current->membership()->user_id, 'insights'), fn ($q) => $q->where('kind', 'designs'))
            ->orderByDesc('id')->paginate(20);
        $rows->through(fn ($run) => $this->present($run));

        return response()->json($rows);
    }

    public function store(Request $request, CurrentWorkspace $current, string $banner): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['designs', 'insights'])], 'request_key' => ['required', 'uuid']]);
        $run = $this->ai->request($this->campaign($current, $banner), $current->membership()->user_id, $data['kind'], $data['request_key']);

        return response()->json(['data' => $this->present($run)], 202);
    }

    public function show(CurrentWorkspace $current, string $banner, string $run): JsonResponse
    {
        return response()->json(['data' => $this->present($this->run($current, $banner, $run))]);
    }

    public function review(Request $request, CurrentWorkspace $current, string $banner, string $run): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'reject'])],
            'proposal' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'integer', 'between:0,2'],
            'note' => ['required', 'string', 'max:500', 'not_regex:/[<>]/']]);
        $record = $this->run($current, $banner, $run);
        $draft = $this->ai->review($this->campaign($current, $banner), $record, $current->membership()->user_id,
            $data['decision'], isset($data['proposal']) ? (int) $data['proposal'] : null, $data['note']);

        return response()->json(['data' => ['request' => $this->present($record), 'draft_campaign' => $draft?->public_id, 'published' => false]]);
    }

    private function campaign(CurrentWorkspace $current, string $id): BannerCampaign
    {
        return BannerCampaign::where('webshop_id', $current->webshop()->id)->where('public_id', $id)->first()
            ?? throw new ApiException('Banner not found.', 404, 'not_found');
    }

    private function run(CurrentWorkspace $current, string $banner, string $id): BannerAiRequest
    {
        $run = BannerAiRequest::where('campaign_id', $this->campaign($current, $banner)->id)->where('public_id', $id)->first()
            ?? throw new ApiException('Banner AI request not found.', 404, 'not_found');
        if (! $this->ai->allowed($run->webshop_id, $current->membership()->user_id, $run->kind)) {
            throw new ApiException('This action requires current banner and order permissions.', 403, 'banner_ai_forbidden');
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function present(BannerAiRequest $run): array
    {
        $result = BannerAiResult::where('request_id', $run->id)->first();
        $review = BannerAiReview::where('request_id', $run->id)->first();
        $proposals = [];
        foreach ($result?->getAttribute('proposals') ?? [] as $index => $proposal) {
            $proposals[] = ['index' => $index, 'hypothesis' => $proposal['hypothesis'], 'evidence_refs' => $proposal['evidence_refs'],
                'editable_config' => $this->ai->editable($run, $proposal)];
        }

        return ['id' => $run->public_id, 'kind' => $run->kind, 'status' => $run->status, 'failure_code' => $run->failure_code,
            'source_revision' => $run->revision, 'evidence' => $run->context['evidence'], 'proposals' => $proposals,
            'review' => $review === null ? null : ['decision' => $review->getAttribute('decision'), 'proposal' => $review->getAttribute('proposal')],
            'human_review_required' => true, 'measured_uplift' => null];
    }
}
