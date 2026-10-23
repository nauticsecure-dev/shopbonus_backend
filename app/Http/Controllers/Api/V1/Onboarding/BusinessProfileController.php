<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\UpdateBusinessProfileRequest;
use App\Http\Resources\BusinessProfileResource;
use App\Models\User;
use App\Services\Onboarding\BusinessProfileService;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

class BusinessProfileController extends Controller
{
    public function __construct(private readonly BusinessProfileService $profiles) {}

    public function show(CurrentWorkspace $current): JsonResponse
    {
        return BusinessProfileResource::make($this->profiles->forWebshop($current->webshop()))->response()->setStatusCode(200);
    }

    public function update(UpdateBusinessProfileRequest $request, CurrentWorkspace $current): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profiles->update($current->webshop(), $user, $request->validated(), $request->boolean('confirm'));

        return BusinessProfileResource::make($profile)->response()->setStatusCode(200);
    }
}
