<?php

namespace App\Services\Answer24;

use App\Models\OutboxMessage;
use App\Support\SignatureV1;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Signed outbound calls to Answer24 (contract `shopbonus-answer24-v1`,
 * Shopbonus-defined until Answer24 publishes its API: OPEN-ANSWER24-CONTRACT).
 * Every request carries the outbox message ID as event ID and idempotency
 * key, so a retried delivery is recognisable on their side.
 */
class Answer24Client
{
    /**
     * @return array{ok: bool, retryable: bool, http_status: int|null, reference: string|null, error: string|null}
     */
    public function send(OutboxMessage $message): array
    {
        $config = config('shopbonus.answer24');
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $secret = (string) ($config['signing_secret'] ?? '');

        if ($base === '' || $secret === '') {
            return $this->result(false, true, null, null, 'not_configured');
        }

        $reference = $message->payload['remote_reference'] ?? $message->payload['claim_id'] ?? '';
        $path = match ($message->type) {
            'answer24.contact_requested' => $config['contact_path'],
            'answer24.contact_cancelled', 'answer24.order_withdrawn' => $config['cancel_path'],
            'answer24.claim_completed', 'answer24.claim_expired' => $config['claim_status_path'],
            default => null,
        };

        if ($path === null) {
            return $this->result(false, false, null, null, 'unknown_message_type');
        }

        $body = (string) json_encode(['event' => $message->type, 'event_id' => $message->public_id] + $message->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::withHeaders([
                'X-Shopbonus-Signature' => SignatureV1::header($secret, $body),
                'X-Shopbonus-Event-Id' => $message->public_id,
                'X-Shopbonus-Topic' => $message->type,
                'Idempotency-Key' => $message->idempotency_key,
                'Accept' => 'application/json',
            ])->timeout(10)->connectTimeout(5)
                ->withBody($body, 'application/json')
                ->post($base.str_replace('{reference}', rawurlencode((string) $reference), $path));
        } catch (ConnectionException) {
            return $this->result(false, true, null, null, 'connection_failed');
        }

        if ($response->successful()) {
            $remote = $response->json('reference');

            return $this->result(true, false, $response->status(), is_string($remote) ? mb_substr($remote, 0, 120) : null, null);
        }

        $retryable = $response->serverError() || in_array($response->status(), [408, 425, 429], true);

        return $this->result(false, $retryable, $response->status(), null, 'http_'.$response->status());
    }

    /** @return array{ok: bool, retryable: bool, http_status: int|null, reference: string|null, error: string|null} */
    private function result(bool $ok, bool $retryable, ?int $status, ?string $reference, ?string $error): array
    {
        return ['ok' => $ok, 'retryable' => $retryable, 'http_status' => $status, 'reference' => $reference, 'error' => $error];
    }
}
