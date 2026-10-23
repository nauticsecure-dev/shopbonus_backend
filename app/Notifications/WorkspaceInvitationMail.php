<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to join a webshop workspace. Sent to an email address (the
 * invitee may not have an account yet) via an on-demand notifiable.
 * Encrypted because the token is in the payload.
 */
class WorkspaceInvitationMail extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly string $webshopName,
        public readonly string $inviterName,
        public readonly string $role,
        public readonly int $validDays,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function acceptUrl(): string
    {
        return config('shopbonus.identity.frontend_url').'/invitations/accept#token='.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity.invitation_subject', ['webshop' => $this->webshopName]))
            ->line(__('identity.invitation_line', ['inviter' => $this->inviterName, 'webshop' => $this->webshopName, 'role' => __('identity.role_'.$this->role)]))
            ->action(__('identity.invitation_action'), $this->acceptUrl())
            ->line(__('identity.invitation_expiry', ['days' => $this->validDays]));
    }
}
