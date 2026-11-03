<?php

namespace App\Integrations\Connectors;

use App\Enums\EcommercePlatform;

/** Lightspeed eCom E-Series uses Ecwid's API, never the C-Series/Retail API. */
final class LightspeedEConnector extends EcwidConnector
{
    public function platform(): EcommercePlatform
    {
        return EcommercePlatform::LightspeedE;
    }
}
