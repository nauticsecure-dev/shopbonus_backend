<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Code sent to the NEW address of a pending email change. Encrypted because
 * the code is in the queue payload. Mandatory account mail.
 */
class EmailChangeCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly string $firstName,
        public readonly int $ttlMinutes,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity.email_change_subject'))
            ->greeting(__('identity.verification_greeting', ['name' => $this->firstName]))
            ->line(__('identity.email_change_line'))
            ->line('**'.$this->code.'**')
            ->line(__('identity.email_change_expiry', ['minutes' => $this->ttlMinutes]));
    }
}
