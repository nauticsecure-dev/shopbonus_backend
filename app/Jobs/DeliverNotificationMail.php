<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeliverNotificationMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId)
    {
        $this->afterCommit();
    }

    public function handle(NotificationMail $mail): void
    {
        $mail->send($this->deliveryId);
    }
}
