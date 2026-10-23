<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Services\Identity\EmailVerificationCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationCodes $codes) {}

    public function verify(VerifyEmailCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return MeResource::make($this->codes->verify($user, (string) $request->validated('code')))
            ->response()->setStatusCode(200);
    }

    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $challenge = $this->codes->issue($user);

        return response()->json([
            'data' => [
                'sent' => true,
                'expires_at' => $challenge->expires_at->toIso8601String(),
                'resend_available_in_seconds' => (int) config('shopbonus.identity.verification.resend_cooldown_seconds'),
            ],
        ], 202);
    }
}
