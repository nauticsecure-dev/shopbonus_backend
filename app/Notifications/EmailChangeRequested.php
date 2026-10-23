<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security notice to the CURRENT address that an email change was
 * requested or completed. Mandatory account mail; never contains the code.
 * Sent on demand because after completion the old address is no longer the
 * user's email.
 */
class EmailChangeRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $maskedNewEmail, public readonly bool $completed, public readonly string $firstName)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = $this->completed ? 'email_changed' : 'email_change_requested';

        return (new MailMessage)
            ->subject(__("identity.{$key}_subject"))
            ->greeting(__('identity.verification_greeting', ['name' => $this->firstName]))
            ->line(__("identity.{$key}_line", ['email' => $this->maskedNewEmail]))
            ->line(__('identity.email_change_not_you'));
    }
}
