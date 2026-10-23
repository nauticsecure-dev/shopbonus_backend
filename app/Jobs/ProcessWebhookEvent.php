<?php

namespace App\Jobs;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\WebhookEventStatus;
use App\Events\OrderEventVerified;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Data\NormalizedOrder;
use App\Integrations\Data\VerifiedEvent;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Processes one verified webhook event exactly once (status guard under
 * row lock). Connection status moves on observed evidence only:
 * a verified ping → testing; a verified complete order → connected;
 * an incomplete first order → limited (additional integration required).
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $eventId)
    {
        $this->afterCommit();
    }

    public function handle(ConnectorRegistry $registry, AuditLogger $audit): void
    {
        DB::transaction(function () use ($registry, $audit) {
            $event = WebhookEvent::with('connection.webshop')->lockForUpdate()->find($this->eventId);

            if ($event === null || $event->status !== WebhookEventStatus::Verified) {
                return; // already processed (or pruned): idempotent
            }

            $connection = $event->connection;
            $kind = $event->result['kind'] ?? VerifiedEvent::KIND_OTHER;

            if ($kind === VerifiedEvent::KIND_PING) {
                if ($connection->status === ConnectionStatus::SetupRequired) {
                    $connection->forceFill(['status' => ConnectionStatus::Testing])->save();
                }
                $this->finish($event, WebhookEventStatus::Processed, ['kind' => $kind]);

                return;
            }

            if ($kind !== VerifiedEvent::KIND_ORDER) {
                // Refunds and other topics are handled by order ingestion (B05).
                $this->finish($event, WebhookEventStatus::Processed, ['kind' => $kind]);

                return;
            }

            $payload = json_decode((string) $event->payload, true);
            if (! is_array($payload)) {
                $this->finish($event, WebhookEventStatus::Failed, ['kind' => $kind], 'invalid_json');

                return;
            }

            $order = $registry->for($connection->platform)->normalizeOrder($payload);
            $this->updateConnection($connection, $order, $audit);
            $this->finish($event, WebhookEventStatus::Processed, $order->summary());

            OrderEventVerified::dispatch($event, $connection, $order);
        });
    }

    private function updateConnection(WebshopConnection $connection, NormalizedOrder $order, AuditLogger $audit): void
    {
        $attributes = ['last_verified_order_at' => now()];
        $previous = $connection->status;

        if ($order->isComplete() && $previous !== ConnectionStatus::Connected) {
            $attributes += ['status' => ConnectionStatus::Connected, 'connected_at' => now(), 'limitations' => null];
        } elseif (! $order->isComplete() && in_array($previous, [ConnectionStatus::SetupRequired, ConnectionStatus::Testing], true)) {
            $attributes += ['status' => ConnectionStatus::Limited, 'limitations' => ['additional_integration_required']];
        }

        $connection->forceFill($attributes)->save();

        if ($connection->status !== $previous) {
            $audit->record('integration.status_changed', category: AuditCategory::Integration, resource: $connection,
                webshopId: $connection->webshop_id,
                metadata: ['from' => $previous->value, 'to' => $connection->status->value, 'missing_fields' => $order->missingFields()]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(WebhookEvent $event, WebhookEventStatus $status, array $result, ?string $error = null): void
    {
        $event->forceFill(['status' => $status, 'result' => $result, 'error_code' => $error, 'processed_at' => now()])->save();
    }
}
