<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * POST /forgot-password answers identically whether the email exists, is
 * unknown or was throttled by the broker, so it cannot be used to discover
 * accounts (Trello #4).
 */
class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(public readonly string $status = '') {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'If an account exists for this email address, a password reset link has been sent.',
        ]);
    }
}
