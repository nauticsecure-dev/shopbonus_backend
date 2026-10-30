<?php

namespace App\Enums;

enum OfferStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
    case Archived = 'archived';

    /** Allowed transitions; anything else is refused. */
    public function canBecome(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::Scheduled, self::Active, self::Ended, self::Archived],
            self::Scheduled => [self::Active, self::Paused, self::Ended, self::Archived],
            self::Active => [self::Paused, self::Ended, self::Archived],
            self::Paused => [self::Active, self::Scheduled, self::Ended, self::Archived],
            self::Ended => [self::Archived],
            self::Archived => [],
        }, true);
    }

    /** Public/lifecycle label. Storage keeps `ended`; callers display `expired`. */
    public function publicValue(): string
    {
        return $this === self::Ended ? 'expired' : $this->value;
    }

    /** Only a live active offer can be reserved or purchased. */
    public function isPurchasableStorage(): bool
    {
        return $this === self::Active;
    }
}
