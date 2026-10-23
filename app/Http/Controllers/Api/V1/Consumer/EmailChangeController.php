<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Controller;
use App\Services\Consumer\EmailChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Secured change of the login email (Trello #10 E2E 4).
 */
class EmailChangeController extends Controller
{
    public function __construct(private readonly EmailChange $emailChange) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'current_password' => ['required', 'string', 'max:255'],
        ]);

        $pending = $this->emailChange->request($request->user(), $data['email'], $data['current_password']);

        return response()->json(['data' => [
            'pending_email' => $pending->new_email,
            'expires_at' => $pending->expires_at->toIso8601String(),
            'message' => 'Enter the code we sent to the new address. Your current address stays active until then.',
        ]], 202);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        $user = $this->emailChange->confirm($request->user(), $data['code']);

        return response()->json(['data' => ['email' => $user->email, 'email_verification' => 'verified']]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->emailChange->cancel($request->user());

        return response()->json(['data' => ['pending_email' => null]]);
    }
}
