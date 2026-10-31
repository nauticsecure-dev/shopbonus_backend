<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Enums\AuditCategory;
use App\Enums\WebhookEventStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\Data\VerifiedEvent;
use App\Integrations\ShopwareAppRegistration;
use App\Jobs\ProcessWebhookEvent;
use App\Models\ShopwareAppShop;
use App\Models\WebhookEvent;
use App\Models\WebshopConnection;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shopware app webhooks (one manifest URL for every shop). The body is signed with the shop secret from
 * the registration handshake. Entity-written payloads carry only IDs, so `order.written` becomes one
 * ID-only native lookup per order on the bound connection; payload fields are never order evidence.
 * Payment/refund state events (`state_enter.order_transaction.state.*`) carry the order; only its ID is
 * used, for the same native lookup, so recovery does not wait for an `order.written`.
 * `app.deleted` revokes the binding; other events are acknowledged and ignored.
 */
class ShopwareAppWebhookController extends Controller
{
    private const MAX_BODY = 1_048_576;

    private const MAX_ORDERS = 25;

    private const WINDOW = 600;

    /** Transaction states whose entry changes what Shopbonus records (payment, refund, reversal). */
    private const TRANSACTION_STATES = ['paid', 'paid_partially', 'refunded', 'refunded_partially', 'cancelled', 'failed', 'chargeback'];

    public function __construct(private readonly ShopwareAppRegistration $registration, private readonly AuditLogger $audit) {}

    public function receive(Request $request): JsonResponse
    {
        if (! config('integrations.shopware_app.enabled')) {
            throw new ApiException('The Shopware app is not configured.', 503, 'shopware_app_not_configured');
        }
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY) {
            throw new ApiException('The request body is too large.', 413, 'payload_too_large');
        }
        $p = json_decode($body, true);
        $shopId = is_array($p) && is_array($p['source'] ?? null) ? ($p['source']['shopId'] ?? null) : null;
        $shop = is_string($shopId) && preg_match('/\A[A-Za-z0-9]{1,64}\z/D', $shopId)
            ? ShopwareAppShop::where('shop_id', $shopId)->whereNotNull('confirmed_at')->first() : null;
        if ($shop === null || ! $this->registration->signedByShop($shop, $body, $request->header('shopware-shop-signature'))) {
            throw $this->rejected('signature_invalid');
        }
        $ts = $p['timestamp'] ?? null;
        $event = $p['data']['event'] ?? null;
        if (! is_int($ts) || abs(now()->getTimestamp() - $ts) > self::WINDOW || ! is_string($event) || strlen($event) > 100) {
            throw $this->rejected('event_invalid');
        }
        $eventId = $p['source']['eventId'] ?? null;
        $eventId = is_string($eventId) && preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $eventId) ? $eventId : hash('sha256', $body);

        return match ($event) {
            'app.deleted' => $this->deleted($shop, ($p['data']['payload']['keepUserData'] ?? false) === true),
            'app.activated', 'app.deactivated' => $this->lifecycle($shop, $event),
            'order.written' => $this->orders($shop, $p['data']['payload'] ?? null, $eventId),
            default => str_starts_with($event, 'state_enter.order_transaction.state.')
                && in_array(substr($event, strlen('state_enter.order_transaction.state.')), self::TRANSACTION_STATES, true)
                ? $this->transactionState($shop, $p['data']['payload'] ?? null, $eventId, $event)
                : $this->ack(['ignored' => true]),
        };
    }

    private function deleted(ShopwareAppShop $shop, bool $keep): JsonResponse
    {
        $this->registration->revoke($shop->id, $keep);

        return $this->ack(['revoked' => true]);
    }

    private function lifecycle(ShopwareAppShop $shop, string $event): JsonResponse
    {
        // Deactivation makes native reads fail with the provider's own authentication error; nothing is inferred.
        $this->audit->record('integration.shopware_app_'.substr($event, 4), category: AuditCategory::Integration, resource: $shop,
            metadata: ['shop_id' => $shop->shop_id]);

        return $this->ack([]);
    }

    private function orders(ShopwareAppShop $shop, mixed $payload, string $eventId): JsonResponse
    {
        if (! is_array($payload) || ! array_is_list($payload)) {
            throw $this->rejected('event_invalid');
        }
        $ids = [];
        foreach ($payload as $row) {
            $key = is_array($row) ? ($row['primaryKey'] ?? null) : null;
            if (is_array($row) && isset($row['entity']) && $row['entity'] !== 'order') {
                continue;
            }
            if (! is_string($key) || ! preg_match('/\A[a-f0-9]{32}\z/D', $key)) {
                throw $this->rejected('event_invalid');
            }
            $ids[$key] = true;
        }
        if (count($ids) > self::MAX_ORDERS) {
            throw $this->rejected('event_invalid');
        }

        return $this->queueLookups($shop, array_keys($ids), $eventId, 'order.written');
    }

    private function transactionState(ShopwareAppShop $shop, mixed $payload, string $eventId, string $event): JsonResponse
    {
        $id = is_array($payload) && is_array($payload['order'] ?? null) ? ($payload['order']['id'] ?? null) : null;
        if (! is_string($id) || ! preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw $this->rejected('event_invalid');
        }

        return $this->queueLookups($shop, [$id], $eventId, $event);
    }

    /** @param list<string> $ids */
    private function queueLookups(ShopwareAppShop $shop, array $ids, string $eventId, string $topic): JsonResponse
    {
        if ($shop->connection_id === null || $ids === []) {
            return $this->ack(['ignored' => true]);
        }

        $queued = DB::transaction(function () use ($shop, $ids, $eventId, $topic) {
            $c = WebshopConnection::whereKey($shop->connection_id)->lockForUpdate()->first();
            if ($c === null || ! $c->status->receivesEvents() || $c->credential('app_shop_id') !== $shop->shop_id) {
                return 0;
            }
            $count = 0;
            foreach ($ids as $id) {
                $derived = json_encode(['source' => 'shopware_app', 'event' => $topic, 'event_id' => $eventId, 'order_id' => $id], JSON_THROW_ON_ERROR);
                try {
                    $stored = WebhookEvent::create([
                        'source_method' => 'webhook', 'credential_generation' => $c->credential_generation,
                        'connection_id' => $c->id, 'webshop_id' => $c->webshop_id,
                        'dedupe_key' => 'shopware-app:'.$eventId.':'.$id, 'topic' => $topic,
                        'status' => WebhookEventStatus::Verified, 'payload' => $derived, 'payload_hash' => hash('sha256', $derived),
                        'result' => ['kind' => VerifiedEvent::KIND_ORDER_LOOKUP], 'received_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    WebhookEvent::where('connection_id', $c->id)->where('dedupe_key', 'shopware-app:'.$eventId.':'.$id)->increment('delivery_count');

                    continue;
                }
                ProcessWebhookEvent::dispatch($stored->id);
                $count++;
            }
            $c->forceFill(['last_event_at' => now()])->save();

            return $count;
        });

        return $this->ack(['lookups_queued' => $queued]);
    }

    /** @param array<string,mixed> $extra */
    private function ack(array $extra): JsonResponse
    {
        return response()->json(['data' => ['received' => true] + $extra], 200);
    }

    private function rejected(string $reason): ApiException
    {
        return new ApiException('The webhook could not be verified.', 401, 'webhook_rejected', ['reason' => $reason]);
    }
}
