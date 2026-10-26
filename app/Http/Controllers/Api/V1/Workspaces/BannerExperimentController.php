<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BannerCampaign;
use App\Services\Banners\BannerExperiments;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerExperimentController extends Controller
{
    public function show(CurrentWorkspace $current, string $banner, BannerExperiments $experiments): JsonResponse
    {
        return response()->json(['data' => $experiments->report($this->campaign($current, $banner))]);
    }

    public function store(Request $request, CurrentWorkspace $current, string $banner, BannerExperiments $experiments): JsonResponse
    {
        $data = $request->validate(['control_version' => ['present', 'nullable', 'integer', 'min:1'],
            'variant_version' => ['required', 'integer', 'min:1'], 'variant_bps' => ['required', 'integer', 'between:1000,9000']]);

        return response()->json(['data' => $experiments->start($this->campaign($current, $banner),
            $data['control_version'] === null ? null : (int) $data['control_version'], (int) $data['variant_version'], (int) $data['variant_bps'], $current->membership()->user_id)], 201);
    }

    public function stop(CurrentWorkspace $current, string $banner, BannerExperiments $experiments): JsonResponse
    {
        $campaign = $this->campaign($current, $banner);
        $experiments->stop($campaign, $current->membership()->user_id);

        return response()->json(['data' => $experiments->report($campaign)]);
    }

    private function campaign(CurrentWorkspace $current, string $id): BannerCampaign
    {
        return BannerCampaign::where('webshop_id', $current->webshop()->id)->where('public_id', $id)->first()
            ?? throw new ApiException('Banner not found.', 404, 'not_found');
    }
}
