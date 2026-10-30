<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Enums\AuditCategory;
use App\Enums\AuditOutcome;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ShopifyComplianceRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shopify mandatory compliance webhooks (app-level URI from shopify.app.toml `compliance_topics`).
 *
 * Signed with the app client secret over the raw body; an invalid HMAC must return 401. A verified
 * request is stored once per X-Shopify-Webhook-Id and acknowledged with 200. Nothing is erased or
 * exported automatically: retention is a legal decision (D-013/D-099), resolved by an operator.
 */
class ShopifyComplianceController extends Controller
{
    private const MAX_BODY = 1_048_576;

    public function __construct(private readonly AuditLogger $audit) {}

    public function receive(Request $request): JsonResponse
    {
        $secret = config('integrations.shopify_oauth.client_secret');
        if (! is_string($secret) || strlen($secret) < 16) {
            throw new ApiException('The Shopify app is not configured.', 503, 'shopify_app_not_configured');
        }
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY) {
            throw new ApiException('The request body is too large.', 413, 'payload_too_large');
        }
        $signature = base64_decode((string) $request->header('X-Shopify-Hmac-Sha256', ''), true);
        if ($signature === false || ! hash_equals(hash_hmac('sha256', $body, $secret, true), $signature)) {
            $this->rejected('signature_invalid');

            throw new ApiException('The webhook could not be verified.', 401, 'webhook_rejected', ['reason' => 'signature_invalid']);
        }
        $topic = (string) $request->header('X-Shopify-Topic', '');
        $webhookId = trim((string) $request->header('X-Shopify-Webhook-Id', ''));
        if (! in_array($topic, ShopifyComplianceRequest::TOPICS, true) || $webhookId === '' || strlen($webhookId) > 100) {
            $this->rejected('invalid_event_headers');

            throw new ApiException('The webhook could not be verified.', 401, 'webhook_rejected', ['reason' => 'invalid_event_headers']);
        }
        $eventId = trim((string) $request->header('X-Shopify-Event-Id', ''));

        // Authentic but unexpected bodies are kept for review rather than dropped: a privacy request is never lost.
        $payload = json_decode($body, true);
        $valid = is_array($payload) && $this->validShape($topic, $payload);
        $domain = $valid ? strtolower($payload['shop_domain']) : null;

        return DB::transaction(function () use ($webhookId, $eventId, $topic, $domain, $payload, $valid, $body) {
            try {
                $stored = ShopifyComplianceRequest::create([
                    'webhook_id' => $webhookId,
                    'event_id' => $eventId === '' || strlen($eventId) > 100 ? null : $eventId,
                    'topic' => $topic,
                    'shop_domain' => $domain,
                    'shop_id' => $valid ? $payload['shop_id'] : null,
                    'payload_valid' => $valid,
                    'payload' => $body,
                    'payload_hash' => hash('sha256', $body),
                    'received_at' => now(),
                    // Shopify: complete the action within 30 days of receiving the request.
                    'due_at' => now()->addDays(30),
                ]);
            } catch (UniqueConstraintViolationException) {
                ShopifyComplianceRequest::where('webhook_id', $webhookId)->increment('delivery_count');

                return response()->json(['data' => ['received' => true, 'duplicate' => true]], 200);
            }
            $this->audit->record('integration.shopify_compliance_received', category: AuditCategory::Integration,
                resource: $stored, metadata: ['topic' => $topic, 'shop_domain' => $domain, 'payload_valid' => $valid]);

            return response()->json(['data' => ['received' => true, 'duplicate' => false]], 200);
        });
    }

    /** @param array<mixed> $p */
    private function validShape(string $topic, array $p): bool
    {
        $id = fn ($v) => is_int($v) && $v > 0;
        $ids = fn ($v) => is_array($v) && array_is_list($v) && count($v) <= 10_000 && array_filter($v, fn ($x) => ! $id($x)) === [];
        $str = fn ($v, int $max) => $v === null || (is_string($v) && strlen($v) <= $max);
        if (! $id($p['shop_id'] ?? null) || ! is_string($p['shop_domain'] ?? null)
            || ! preg_match('/\A[a-z0-9][a-z0-9-]{0,62}\.myshopify\.com\z/D', strtolower($p['shop_domain']))) {
            return false;
        }
        if ($topic === 'shop/redact') {
            return true;
        }
        $customer = $p['customer'] ?? null;
        if (! is_array($customer) || ! (($customer['id'] ?? null) === null || $id($customer['id']))
            || ! $str($customer['email'] ?? null, 320) || ! $str($customer['phone'] ?? null, 64)) {
            return false;
        }

        return $topic === 'customers/redact'
            ? $ids($p['orders_to_redact'] ?? null)
            : $ids($p['orders_requested'] ?? null) && is_array($p['data_request'] ?? null) && $id($p['data_request']['id'] ?? null);
    }

    /** At most once per minute per reason, so forged floods cannot flood the audit trail. */
    private function rejected(string $reason): void
    {
        if (Cache::add('shopify-compliance-rejected:'.$reason, 1, 60)) {
            $this->audit->recordSafely('integration.shopify_compliance_rejected', AuditOutcome::Denied, AuditCategory::Integration,
                metadata: ['reason' => $reason], httpStatus: 401);
        }
    }
}
