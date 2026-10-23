<?php

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * The request could not be authenticated as coming from the provider.
 * `reason` is a short machine code (never secret material).
 */
class InvalidWebhook extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Webhook rejected: {$reason}");
    }
}
