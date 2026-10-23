<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Code sent to an administrative mailbox at the webshop's domain to prove
 * domain control. Encrypted because the code is in the queue payload.
 */
class OwnershipVerificationCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly string $webshopName,
        public readonly string $domain,
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
            ->subject(__('identity.ownership_subject', ['domain' => $this->domain]))
            ->line(__('identity.ownership_line', ['webshop' => $this->webshopName, 'domain' => $this->domain]))
            ->line('**'.$this->code.'**')
            ->line(__('identity.ownership_expiry', ['minutes' => $this->ttlMinutes]));
    }
}
