<?php

namespace App\Integrations\Data;

/** Per-capability availability reported by adapters and the platform catalogue. */
enum CapabilityState: string
{
    case Available = 'AVAILABLE';
    case Limited = 'LIMITED';
    case Unsupported = 'UNSUPPORTED';
}
