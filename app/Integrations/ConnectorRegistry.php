<?php

namespace App\Integrations;

use App\Enums\EcommercePlatform;
use App\Exceptions\ApiException;
use App\Integrations\Connectors\GenericSignedWebhookConnector;
use App\Integrations\Contracts\Connector;

/**
 * Resolves the connector for a platform from config/integrations.php.
 * A platform without a connector cannot be connected.
 */
class ConnectorRegistry
{
    public function isAvailable(EcommercePlatform $platform): bool
    {
        return $this->entry($platform)['connector'] !== null;
    }

    public function for(EcommercePlatform $platform): Connector
    {
        $class = $this->entry($platform)['connector'];

        if ($class === null) {
            throw new ApiException(
                'This platform cannot be connected yet.',
                409,
                'platform_not_available',
                ['reason' => $this->entry($platform)['blocked_reason'] ?? null],
            );
        }

        return $class === GenericSignedWebhookConnector::class ? new GenericSignedWebhookConnector($platform) : app($class);
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(EcommercePlatform $platform): array
    {
        $entry = config("integrations.platforms.{$platform->value}");

        return is_array($entry) ? $entry + ['connector' => null] : ['connector' => null, 'name' => $platform->value];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        $capabilities = app(CapabilityMatrix::class);

        return array_map(function (EcommercePlatform $platform) use ($capabilities) {
            $entry = $this->entry($platform);

            return [
                'platform' => $platform->value,
                'name' => $entry['name'] ?? $platform->value,
                'priority' => $entry['priority'] ?? null,
                'available' => $entry['connector'] !== null,
                'method' => $entry['method'] ?? null,
                'auth' => $entry['auth'] ?? null,
                'reliability' => $entry['reliability'] ?? null,
                'limitations' => $entry['limitations'] ?? [],
                'verification' => $entry['verification'] ?? 'unverified',
                'profile' => $entry['profile'] ?? null,
                'blocked_reason' => $entry['blocked_reason'] ?? null,
                'capabilities' => $capabilities->forPlatform($platform),
                'category' => in_array($platform, [EcommercePlatform::LightspeedC, EcommercePlatform::LightspeedE], true)
                    ? 'marketplace' : 'webshop',
            ];
        }, EcommercePlatform::cases());
    }
}
