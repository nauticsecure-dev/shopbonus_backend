<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\MerchantRegistrationProgress;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

class OnboardingController extends Controller
{
    public function show(
        CurrentWorkspace $current,
        OnboardingProgress $progress,
        MerchantRegistrationProgress $registration,
    ): JsonResponse {
        $webshop = $current->webshop()->loadMissing(['businessProfile', 'publicProfile']);
        $compliance = $progress->for($webshop);

        return response()->json([
            'data' => [
                ...$compliance,
                // Trello #9 merchant UI (Details → Voucher → Activate). Completing
                // voucher_setup never activates; blockers are listed for Step 3.
                'registration' => $registration->for($webshop),
            ],
        ]);
    }
}
