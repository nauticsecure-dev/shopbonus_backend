<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mails a 6-digit verification code. Queued so SMTP never blocks signup, and
 * encrypted because the plain code sits in the queue payload until sent.
 * Mandatory account mail: not subject to marketing consent.
 */
class EmailVerificationCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly int $ttlMinutes,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity.verification_subject'))
            ->greeting(__('identity.verification_greeting', ['name' => $notifiable->first_name]))
            ->line(__('identity.verification_line'))
            ->line('**'.$this->code.'**')
            ->line(__('identity.verification_expiry', ['minutes' => $this->ttlMinutes]));
    }
}
