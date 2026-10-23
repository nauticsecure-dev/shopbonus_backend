<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Identity\EmailVerificationCodes;
use Illuminate\Auth\Events\Registered;

/**
 * Sends the first OTP right after signup. Laravel's signed-link
 * verification is never triggered because User does not implement
 * MustVerifyEmail, so only one delivery method exists.
 */
class SendVerificationCodeOnRegistration
{
    public function __construct(private readonly EmailVerificationCodes $codes) {}

    public function handle(Registered $event): void
    {
        if ($event->user instanceof User && $event->user->email_verified_at === null) {
            $this->codes->issue($event->user, enforceCooldown: false);
        }
    }
}
