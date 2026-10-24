<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mails the 6-digit admin step-up code. Queued and encrypted like the
 * verification code: the plain code only exists in the queue payload.
 */
class StepUpCode extends Notification implements ShouldBeEncrypted, ShouldQueue
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
            ->subject(__('identity.step_up_subject'))
            ->greeting(__('identity.verification_greeting', ['name' => $notifiable->first_name]))
            ->line(__('identity.step_up_line'))
            ->line('**'.$this->code.'**')
            ->line(__('identity.step_up_expiry', ['minutes' => $this->ttlMinutes]));
    }
}
