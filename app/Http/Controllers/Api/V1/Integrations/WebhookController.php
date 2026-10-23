<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Enums\EcommercePlatform;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public inbound provider webhooks (Trello #1 "WebhookReceiver").
 *
 * Nothing is trusted or stored before the connector has verified the
 * signature. Verified events are stored once (dedupe key unique per
 * connection), acknowledged quickly and processed asynchronously.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    public function receive(Request $request, string $platform, string $token): JsonResponse
    {
        $platformEnum = EcommercePlatform::tryFrom($platform);
        $connection = $platformEnum === null ? null : WebshopConnection::where('webhook_token', $token)->first();

        if ($connection === null || $connection->platform !== $platformEnum || ! $connection->status->receivesEvents()) {
            throw new NotFoundHttpException;
        }

        try {
            $event = $this->registry->for($connection->platform)->verifyIncomingEvent($connection, $request);
        } catch (InvalidWebhook $e) {
            $this->recordRejection($connection, $e->reason);

            throw new ApiException('The webhook could not be verified.', 401, 'webhook_rejected', ['reason' => $e->reason]);
        }

        $body = $request->getContent();

        try {
            $stored = WebhookEvent::create([
                'connection_id' => $connection->id,
                'webshop_id' => $connection->webshop_id,
                'dedupe_key' => $event->dedupeKey,
                'topic' => $event->topic,
                'status' => WebhookEventStatus::Verified,
                'payload' => $body,
                'payload_hash' => hash('sha256', $body),
                'result' => ['kind' => $event->kind],
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            WebhookEvent::where('connection_id', $connection->id)->where('dedupe_key', $event->dedupeKey)->increment('delivery_count');

            return response()->json(['data' => ['received' => true, 'duplicate' => true]], 200);
        }

        $connection->forceFill(['last_event_at' => now()])->save();
        ProcessWebhookEvent::dispatch($stored->id);

        return response()->json(['data' => ['received' => true, 'duplicate' => false, 'event_id' => $stored->public_id]], 202);
    }

    /**
     * Rejections are recorded at most once per minute per connection and
     * reason, so a flood of forged requests cannot flood the audit trail.
     */
    private function recordRejection(WebshopConnection $connection, string $reason): void
    {
        if (! Cache::add("webhook-rejected:{$connection->id}:{$reason}", 1, 60)) {
            return;
        }

        $connection->forceFill(['last_error_code' => 'webhook_'.$reason, 'last_error_at' => now()])->save();
        $this->audit->recordSafely('integration.webhook_rejected', AuditOutcome::Denied, AuditCategory::Integration,
            resource: $connection, webshopId: $connection->webshop_id, metadata: ['reason' => $reason], httpStatus: 401);
    }
}
