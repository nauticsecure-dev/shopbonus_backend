<?php

namespace App\Jobs;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\WebhookEventStatus;
use App\Events\OrderEventVerified;
use App\Events\RefundEventVerified;
use App\Integrations\ConnectionReadiness;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Contracts\OrderNotification;
use App\Integrations\Data\PaymentStatus;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\OrderRecovery;
use App\Integrations\ShopifyUninstall;
use App\Models\IntegrationOrderRecovery;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Models\WebshopOrder;
use App\Services\Audit\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Processes one verified webhook event exactly once (status guard under
 * row lock). Connection status moves on observed evidence only:
 * a verified ping → testing; only all seven native-evidence gates → connected;
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
        $candidate = WebhookEvent::find($this->eventId);
        if ($candidate !== null && ($candidate->result['kind'] ?? null) === VerifiedEvent::KIND_DISCONNECT) {
            app(ShopifyUninstall::class)->process($this->eventId);

            return;
        }
        DB::transaction(function () use ($registry, $audit) {
            $candidate = WebhookEvent::find($this->eventId);
            if ($candidate === null) {
                return;
            }
            // Lifecycle mutations always lock connection first, then the event.
            $connection = WebshopConnection::with('webshop')->whereKey($candidate->connection_id)->lockForUpdate()->firstOrFail();
            $event = WebhookEvent::whereKey($this->eventId)->lockForUpdate()->first();

            if ($event === null || $event->status !== WebhookEventStatus::Verified) {
                return; // already processed (or pruned): idempotent
            }

            $kind = $event->result['kind'] ?? VerifiedEvent::KIND_OTHER;

            if ($connection->status === ConnectionStatus::Disconnected || $event->webshop_id !== $connection->webshop_id
                || $event->credential_generation === null || $event->credential_generation !== $connection->credential_generation) {
                $this->finish($event, WebhookEventStatus::Ignored, ['kind' => $kind], 'credential_generation_stale');
                $audit->record('integration.event_stale', category: AuditCategory::Integration, resource: $event,
                    webshopId: $connection->webshop_id, metadata: ['code' => 'credential_generation_stale']);

                return;
            }

            $native = $event->source_method === 'native_api';
            $recovery = $native ? IntegrationOrderRecovery::where('webhook_event_id', $event->id)
                ->where('credential_generation', $connection->credential_generation)->oldest('id')->first() : null;
            if ($native && ($recovery === null || ! app(OrderRecovery::class)->matches($recovery, $connection))) {
                $this->finish($event, WebhookEventStatus::Ignored, ['kind' => $kind], 'connection_changed');

                return;
            }

            if ($kind === VerifiedEvent::KIND_PING) {
                if ($connection->status === ConnectionStatus::SetupRequired) {
                    $connection->forceFill(['status' => ConnectionStatus::Testing])->save();
                }
                $this->finish($event, WebhookEventStatus::Processed, ['kind' => $kind]);

                return;
            }

            $payload = json_decode((string) $event->payload, true);
            $connector = $registry->for($connection->platform);

            if ($kind === VerifiedEvent::KIND_ORDER_LOOKUP && $connector instanceof OrderNotification) {
                $target = $connector->notificationOrderId($connection, (string) $event->payload);
                if ($target === null) {
                    $this->finish($event, WebhookEventStatus::Failed, ['kind' => $kind], 'invalid_order_id');

                    return;
                }
                $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'shopbonus-notification:'.$event->public_id)->toString();
                app(OrderRecovery::class)->request($connection, $target, $key, 'reconciliation');
                $this->finish($event, WebhookEventStatus::Processed, ['kind' => $kind, 'native_lookup_requested' => true]);

                return;
            }

            if ($kind === VerifiedEvent::KIND_REFUND) {
                $target = is_array($payload) ? $connector->refundTarget($payload) : null;
                $this->finish($event, WebhookEventStatus::Processed, ['kind' => $kind, 'order_found' => $target !== null]);

                if ($target !== null) {
                    RefundEventVerified::dispatch($event, $connection, $target);
                }

                return;
            }

            if ($kind !== VerifiedEvent::KIND_ORDER) {
                $this->finish($event, WebhookEventStatus::Ignored, ['kind' => $kind]);

                return;
            }

            if (! is_array($payload)) {
                $this->finish($event, WebhookEventStatus::Failed, ['kind' => $kind], 'invalid_json');

                return;
            }

            $order = $connector->normalizeOrder($payload);
            $stored = $native ? WebshopOrder::where('webshop_id', $connection->webshop_id)->where('platform', $connection->platform->value)
                ->where('external_order_id', $order->externalId)->lockForUpdate()->first() : null;
            $before = $stored === null ? null : $this->financialSnapshot($stored);
            $oldAt = $stored?->provider_updated_at;
            $this->finish($event, WebhookEventStatus::Processed, $order->summary());
            OrderEventVerified::dispatch($event, $connection, $order);
            $event->refresh();
            if ($native) {
                $after = $stored?->fresh();
                $changed = $before !== null && $after !== null && $oldAt !== null && $order->providerUpdatedAt !== null
                    && $order->providerUpdatedAt->greaterThan($oldAt) && $before !== $this->financialSnapshot($after)
                    && ($event->result['ingested'] ?? false) === true;
                $event->forceFill(['result' => ($event->result ?? []) + [
                    'native_external_order_id' => $order->externalId,
                    'native_payment_evidence' => $order->paidAt !== null && in_array($order->paymentStatus,
                        [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true),
                    'native_change_recovered' => $changed,
                    // A refund specifically, not any financial change (a payment alone proves nothing about refunds).
                    'native_refund_recovered' => $changed && $this->refundObserved($before, $this->financialSnapshot($after)),
                ]])->save();
                IntegrationOrderRecovery::where('webhook_event_id', $event->id)->where('credential_generation', $connection->credential_generation)
                    ->where('status', 'staged')->update(['status' => 'succeeded', 'completed_at' => now()]);
            }
            $connection->forceFill(['last_verified_order_at' => now()])->save();
            app(ConnectionReadiness::class)->refresh($connection);
        });
    }

    /** @return array<mixed> */
    private function financialSnapshot(WebshopOrder $order): array
    {
        return [$order->payment_status->value, $order->provider_status, $order->amount_minor, $order->refunded_minor, $order->currency];
    }

    /**
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     */
    private function refundObserved(array $before, array $after): bool
    {
        $refunded = [PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value];

        return ($after[3] ?? 0) > ($before[3] ?? 0) || (in_array($after[0], $refunded, true) && $after[0] !== $before[0]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(WebhookEvent $event, WebhookEventStatus $status, array $result, ?string $error = null): void
    {
        $event->forceFill(['status' => $status, 'result' => $result, 'error_code' => $error, 'processed_at' => now()])->save();
        if ($status === WebhookEventStatus::Ignored) {
            IntegrationOrderRecovery::where('webhook_event_id', $event->id)->where('status', 'staged')
                ->update(['status' => 'superseded', 'last_error_code' => $error]);
        }
        if ($status === WebhookEventStatus::Failed) {
            IntegrationOrderRecovery::where('webhook_event_id', $event->id)->where('status', 'staged')
                ->update(['status' => 'failed', 'last_error_code' => $error]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        DB::transaction(function () {
            $candidate = WebhookEvent::find($this->eventId);
            if ($candidate === null) {
                return;
            }
            $c = WebshopConnection::with('webshop')->whereKey($candidate->connection_id)->lockForUpdate()->first();
            $event = WebhookEvent::whereKey($candidate->id)->lockForUpdate()->first();
            if ($c === null || $event === null || $event->status !== WebhookEventStatus::Verified) {
                return;
            }
            $event->forceFill(['status' => WebhookEventStatus::Failed, 'error_code' => 'event_processing_failed'])->save();
            IntegrationOrderRecovery::where('webhook_event_id', $event->id)->where('status', 'staged')
                ->update(['status' => 'failed', 'last_error_code' => 'event_processing_failed']);
            app(ConnectionReadiness::class)->refresh($c);
        });
    }
}
