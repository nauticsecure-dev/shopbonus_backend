<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Reuses Laravel's configured queue and mail transport; customer prose stays encrypted at rest. */
class DeliverContactMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    // Ambiguous SMTP acceptance must not automatically duplicate a customer's message.
    public int $tries = 1;

    public int $timeout = 60;

    /** @param array{name:string,email:string,subject:string,message:string} $submission */
    public function __construct(public array $submission, public string $support, public string $locale, public string $requestId) {}

    public function handle(): void
    {
        $data = ['submission' => $this->submission, 'locale' => $this->locale, 'confirmation' => false];
        $sent = Mail::send(['html' => 'mail.contact', 'text' => 'mail.contact-text'], $data, function ($mail) {
            $mail->to($this->support)->replyTo($this->submission['email'], $this->submission['name'])
                ->subject(__('contact.support_subject', [], $this->locale).': '.$this->submission['subject']);
        });
        if ($sent === null) {
            throw new \RuntimeException('Contact support transport did not accept the message.');
        }
        // Confirm only after the support transport accepted the message.
        Mail::send(['html' => 'mail.contact', 'text' => 'mail.contact-text'], [...$data, 'confirmation' => true], function ($mail) {
            $mail->to($this->submission['email'])->subject(__('contact.confirmation_subject', [], $this->locale));
        });
        Log::info('contact.delivered', ['request_id' => $this->requestId]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('contact.delivery_failed', ['request_id' => $this->requestId]);
    }
}
