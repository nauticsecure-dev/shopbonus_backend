<?php

namespace Tests\Feature\Integrations;

use App\Enums\EcommercePlatform;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Connectors\LightspeedEConnector;

/** Run the shared provider contract against a separately scoped E-Series connection. */
class LightspeedEConnectorTest extends EcwidConnectorTest
{
    protected const PLATFORM = EcommercePlatform::LightspeedE;

    public function test_registry_resolves_the_e_series_identity(): void
    {
        $connector = app(ConnectorRegistry::class)->for(static::PLATFORM);
        $this->assertInstanceOf(LightspeedEConnector::class, $connector);
        $this->assertSame(static::PLATFORM, $connector->platform());
    }
}
