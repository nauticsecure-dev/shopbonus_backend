<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    public function show(CurrentWorkspace $current, OnboardingProgress $progress): JsonResponse
    {
        $webshop = $current->webshop()->loadMissing('businessProfile');

        return response()->json(['data' => $progress->for($webshop)]);
    }
}
