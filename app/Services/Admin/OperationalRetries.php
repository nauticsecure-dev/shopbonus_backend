<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Jobs\ProcessWebhookEvent;
use App\Models\OutboxMessage;
use App\Models\WebhookEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class OperationalRetries
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function webhook(WebhookEvent $event, string $reason): void
    {
        DB::transaction(function () use ($event, $reason) {
            $event = WebhookEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->status !== WebhookEventStatus::Failed || $event->payload === null || ! $event->connection->status->receivesEvents()) {
                throw ApiException::conflict('Only failed events with a retained payload and active connection can be retried.', 'event_not_retryable');
            }
            $event->forceFill(['status' => WebhookEventStatus::Verified, 'error_code' => null])->save();
            $this->audit->record('admin.webhook_retry_requested', category: AuditCategory::Integration, resource: $event,
                webshopId: $event->webshop_id, metadata: ['reason' => $reason]);
            ProcessWebhookEvent::dispatch($event->id);
        });
    }

    public function outbox(OutboxMessage $message, string $reason): void
    {
        DB::transaction(function () use ($message, $reason) {
            $message = OutboxMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($message->target !== 'answer24' || $message->status !== 'failed') {
                throw ApiException::conflict('Only failed Answer24 messages can be retried.', 'outbox_not_retryable');
            }
            // Retain original payload, public ID and provider idempotency key. Never enable dispatch here.
            $message->forceFill(['status' => 'pending', 'next_attempt_at' => now()])->save();
            $this->audit->record('admin.outbox_retry_requested', category: AuditCategory::Integration, resource: $message,
                webshopId: $message->webshop_id, metadata: ['reason' => $reason]);
        });
    }
}
