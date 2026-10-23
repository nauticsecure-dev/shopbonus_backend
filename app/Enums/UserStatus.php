<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
