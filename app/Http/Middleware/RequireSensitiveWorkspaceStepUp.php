<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Admin\StepUpCodes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional recent-password + emailed OTP step-up for owner-sensitive workspace
 * mutations. Disabled when `shopbonus.workspace.step_up_for_sensitive` is false
 * (PHPUnit default) so existing onboarding/funding tests stay focused on domain
 * behaviour; production defaults to on (Trello #4 / D-060).
 */
class RequireSensitiveWorkspaceStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('shopbonus.workspace.step_up_for_sensitive')) {
            return $next($request);
        }

        $confirmedAt = $request->hasSession() ? (int) $request->session()->get('auth.password_confirmed_at', 0) : 0;
        if (time() - $confirmedAt > (int) config('auth.password_timeout', 10800)) {
            throw new ApiException('Confirm your password to continue.', 423, 'password_confirmation_required');
        }

        if (StepUpCodes::confirmedUntil($request->session()) === null) {
            throw new ApiException('Confirm this action with the code sent to your email.', 423, 'step_up_code_required');
        }

        return $next($request);
    }
}
