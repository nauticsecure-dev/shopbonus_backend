<?php

namespace App\Integrations;

use App\Enums\AuditCategory;
use App\Enums\ConnectionStatus;
use App\Enums\WebhookEventStatus;
use App\Models\IntegrationOrderRecovery;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;

/** Gate evidence is derived from authenticated native ingestion, never merchant-submitted booleans. */
class ConnectionReadiness
{
    /** @return array<string,bool> */
    public function gates(WebshopConnection $c): array
    {
        $r = IntegrationOrderRecovery::where('connection_id', $c->id)->where('credential_generation', $c->credential_generation)->where('purpose', 'verification')->latest('id')->first();
        $event = $r?->webhook_event_id === null ? null : WebhookEvent::find($r->webhook_event_id);
        $bound = $r !== null && app(OrderRecovery::class)->matches($r, $c) && $r->status === 'succeeded'
            && $event !== null && $event->source_method === 'native_api' && $event->credential_generation === $c->credential_generation
            && $event->connection_id === $c->id && $event->webshop_id === $c->webshop_id && $event->status === WebhookEventStatus::Processed;
        $ingested = $bound && ($event->result['ingested'] ?? false) === true;
        $changed = $bound && WebhookEvent::where('connection_id', $c->id)->where('credential_generation', $c->credential_generation)
            ->where('source_method', 'native_api')->where('status', WebhookEventStatus::Processed)
            ->where('result->native_external_order_id', $r->external_order_id)->where('result->native_refund_recovered', true)->exists();

        return [
            'authorization_verified' => $bound,
            'store_identity_matches' => $bound,
            'genuine_order_observed' => $ingested,
            'mandatory_fields_mapped' => $ingested && ($event->result['complete'] ?? false) === true,
            'payment_verified' => $ingested && ($event->result['native_payment_evidence'] ?? false) === true,
            'duplicate_processing_prevented' => $ingested && $event->delivery_count >= 2,
            'changes_refunds_recoverable' => $ingested && $changed,
        ];
    }

    /** Caller holds the connection row lock. */
    public function refresh(WebshopConnection $c): void
    {
        if ($c->status === ConnectionStatus::Disconnected) {
            return;
        }
        $gates = $this->gates($c);
        $observed = $c->events()->where('credential_generation', $c->credential_generation)->where('result->kind', 'order')->exists();
        $complete = ! in_array(false, $gates, true) && $c->last_error_code === null;
        $next = $complete ? ConnectionStatus::Connected : ($observed ? ConnectionStatus::Limited : $c->status);
        if (! $complete && $next === ConnectionStatus::Connected) {
            $next = ConnectionStatus::Limited;
        }
        $previous = $c->status;
        $latestOrder = $c->events()->where('credential_generation', $c->credential_generation)->where('status', WebhookEventStatus::Processed)
            ->where('result->kind', 'order')->latest('id')->first();
        $limits = $complete ? null : ($observed ? [($latestOrder->result['complete'] ?? false) === false ? 'additional_integration_required' : 'connection_quality_gates_pending'] : $c->limitations);
        $c->forceFill(['status' => $next, 'connected_at' => $complete ? ($c->connected_at ?? now()) : null, 'limitations' => $limits])->save();
        if ($previous !== $next) {
            app(AuditLogger::class)->record('integration.status_changed', category: AuditCategory::Integration, resource: $c,
                webshopId: $c->webshop_id, metadata: ['from' => $previous->value, 'to' => $next->value,
                    'missing_gates' => array_keys(array_filter($gates, fn ($passed) => ! $passed))]);
        }
    }
}
