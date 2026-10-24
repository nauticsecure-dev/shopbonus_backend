<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Admin\StepUpCodes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second step-up factor for the most sensitive admin actions: the session
 * must have confirmed an emailed code (POST /admin/step-up/verify) within
 * `shopbonus.admin.step_up.valid_minutes`. Use after `password.recent`.
 * Alias: `step_up.otp`.
 */
class RequireStepUpCode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || StepUpCodes::confirmedUntil($request->session()) === null) {
            throw new ApiException('Confirm this action with the code sent to your email.', 423, 'step_up_code_required');
        }

        return $next($request);
    }
}
