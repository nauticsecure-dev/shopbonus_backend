<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Laravel's reset notification, queued (constant-time forgot-password
 * responses, no SMTP in the request) and encrypted (the token is in the
 * payload). The URL comes from ResetPassword::createUrlUsing (FRONTEND_URL).
 */
class ResetPasswordLink extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('identity.reset_subject'))
            ->line(__('identity.reset_line'))
            ->action(__('identity.reset_action'), $url)
            ->line(__('identity.reset_expiry', ['minutes' => $minutes]));
    }
}
