<?php

namespace App\Enums;

enum WebhookEventStatus: string
{
    case Verified = 'verified';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
