<?php

namespace App\Integrations\Connectors;

use App\Integrations\Contracts\Connector;
use App\Integrations\Exceptions\InvalidWebhook;
use App\Integrations\Exceptions\UnsupportedCapability;
use App\Models\WebshopConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Base for webhook-only connectors: no API lookup, polling or active test.
 */
abstract class WebhookConnector implements Connector
{
    public const MAX_BODY_BYTES = 1_048_576;

    public function testConnection(WebshopConnection $connection): array
    {
        throw new UnsupportedCapability('an active connection test (send a real order instead)');
    }

    public function fetchOrder(WebshopConnection $connection, string $externalId): array
    {
        throw new UnsupportedCapability('order lookup');
    }

    public function syncOrders(WebshopConnection $connection, CarbonImmutable $since): iterable
    {
        throw new UnsupportedCapability('order polling');
    }

    public function disconnect(WebshopConnection $connection): void
    {
        // Nothing to revoke remotely for webhook-only connections; the merchant
        // removes the webhook in their store. Credentials are wiped by ConnectionManager.
    }

    /** @return list<string> */
    public function generatedSecrets(): array
    {
        return [];
    }

    protected function newSecret(): string
    {
        return Str::random(48);
    }

    protected function rawBody(Request $request): string
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidWebhook('payload_too_large');
        }

        return $body;
    }

    protected function requireSecret(WebshopConnection $connection, string $key): string
    {
        return $connection->credential($key) ?? throw new InvalidWebhook('not_configured');
    }

    protected function assertBase64Hmac(string $body, string $secret, ?string $given): void
    {
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

        if (! is_string($given) || $given === '' || ! hash_equals($expected, trim($given))) {
            throw new InvalidWebhook('invalid_signature');
        }
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    protected function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
