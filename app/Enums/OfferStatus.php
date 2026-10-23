<?php

namespace App\Enums;

enum OfferStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';

    /** Allowed transitions; anything else is refused. */
    public function canBecome(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::Active, self::Ended],
            self::Active => [self::Paused, self::Ended],
            self::Paused => [self::Active, self::Ended],
            self::Ended => [],
        }, true);
    }
}
