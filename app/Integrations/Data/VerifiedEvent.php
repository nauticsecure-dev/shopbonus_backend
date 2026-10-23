<?php

namespace App\Integrations\Data;

/**
 * An inbound request whose authenticity the connector has proven.
 */
final readonly class VerifiedEvent
{
    public const KIND_ORDER = 'order';

    public const KIND_REFUND = 'refund';

    public const KIND_PING = 'ping';

    public const KIND_OTHER = 'other';

    public function __construct(
        public string $dedupeKey,
        public ?string $topic,
        public string $kind,
    ) {}
}
