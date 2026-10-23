<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\IntegrationClient;
use App\Models\IntegrationInboundEvent;
use App\Support\SignatureV1;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `integration.signed:<scope>`: authenticates a partner system (Answer24)
 * calling Shopbonus server-to-server (Trello #23 "Protect handoffs with
 * signatures, scoped credentials, idempotency and expiry").
 *
 * - `X-Shopbonus-Key`: the client's key ID; `X-Shopbonus-Signature`: SignatureV1
 *   over the raw body, 5-minute window. Any failure is the same generic 401,
 *   which AuditAccessDenials records.
 * - `X-Shopbonus-Event-Id`: unique per request. A retry with the same ID and
 *   body passes (handlers are idempotent); the same ID with another body is
 *   refused, so a captured ID cannot be reused for a different instruction.
 */
class VerifyIntegrationSignature
{
    public const ATTRIBUTE = 'integration_client';

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $client = IntegrationClient::where('key_id', (string) $request->header('X-Shopbonus-Key', ''))->first();
        $body = $request->getContent();

        if ($client === null || ! $client->isUsable()
            || ! SignatureV1::verify($client->secret, $body, (string) $request->header('X-Shopbonus-Signature', ''))) {
            throw new ApiException('The request signature is missing or invalid.', 401, 'integration_signature_invalid');
        }

        if (! $client->hasScope($scope)) {
            throw new ApiException('This integration key may not call this endpoint.', 403, 'integration_scope_denied', ['required_scope' => $scope]);
        }

        $eventId = trim((string) $request->header('X-Shopbonus-Event-Id', ''));
        if ($eventId === '' || strlen($eventId) > 120) {
            throw new ApiException('X-Shopbonus-Event-Id is required (max 120 characters).', 400, 'event_id_required');
        }

        $this->guardReplay($client, $eventId, hash('sha256', $body), (string) $request->route()?->getName());

        $client->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set(self::ATTRIBUTE, $client);

        return $next($request);
    }

    private function guardReplay(IntegrationClient $client, string $eventId, string $bodyHash, string $route): void
    {
        try {
            IntegrationInboundEvent::create(['client_id' => $client->id, 'event_id' => $eventId, 'body_hash' => $bodyHash, 'route' => $route, 'received_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            $seen = IntegrationInboundEvent::where('client_id', $client->id)->where('event_id', $eventId)->first();

            if ($seen === null || ! hash_equals($seen->body_hash, $bodyHash) || $seen->route !== $route) {
                throw ApiException::conflict('This event ID was already used for a different request.', 'event_id_reused');
            }
        }
    }
}
