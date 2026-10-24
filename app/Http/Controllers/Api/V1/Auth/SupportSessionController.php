<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Admin\SupportSessionController as AdminSupportSessionController;
use App\Http\Controllers\Controller;
use App\Models\ImpersonationSession;
use App\Services\Admin\SupportSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "End session" action of the support-session banner, called with the
 * support token itself.
 */
class SupportSessionController extends Controller
{
    public function endCurrent(Request $request, SupportSessions $sessions): JsonResponse
    {
        $session = $request->attributes->get('support_session');

        if (! $session instanceof ImpersonationSession) {
            throw new ApiException('This request is not part of a support session.', 404, 'not_in_support_session');
        }

        return response()->json(['data' => AdminSupportSessionController::present($sessions->end($session, 'ended_by_session'))]);
    }
}
