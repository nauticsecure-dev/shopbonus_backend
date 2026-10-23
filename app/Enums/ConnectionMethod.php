<?php

namespace App\Enums;

enum ConnectionMethod: string
{
    case Webhook = 'webhook';
    case WebhookWithApiLookup = 'webhook_api';
    case Polling = 'polling';
    case PrivateModule = 'private_module';
}
