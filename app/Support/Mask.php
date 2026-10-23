<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Mask
{
    /**
     * "admin@shop.nl" → "a***@shop.nl": enough for the recipient to
     * recognise the address without disclosing it.
     */
    public static function email(string $email): string
    {
        $local = Str::before($email, '@');
        $domain = Str::after($email, '@');

        return Str::substr($local, 0, 1).str_repeat('*', max(Str::length($local) - 1, 2)).'@'.$domain;
    }
}
