<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\StepUpCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emailed step-up code for sensitive admin actions (password first, then
 * this code). Both routes sit behind `password.recent`.
 */
class StepUpController extends Controller
{
    public function __construct(private readonly StepUpCodes $codes) {}

    public function code(Request $request): JsonResponse
    {
        $challenge = $this->codes->issue($request->user());

        return response()->json(['data' => ['sent' => true, 'expires_at' => $challenge->expires_at->toIso8601String()]], 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        $this->codes->verify($request->user(), $data['code'], $request->session());

        return response()->json(['data' => [
            'confirmed' => true,
            'valid_until' => date(DATE_ATOM, (int) StepUpCodes::confirmedUntil($request->session())),
        ]]);
    }
}
