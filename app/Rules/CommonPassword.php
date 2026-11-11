<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CommonPassword implements ValidationRule
{
    /** Common candidates are rejected locally before the production breach check. */
    private const PASSWORDS = [
        '123456', '12345678', '123456789', '1234567890',
        'password', 'password1', 'password123', 'password123!',
        'qwerty', 'qwerty123', 'qwerty12345', 'qwertyuiop',
        'welcome', 'welcome123', 'welcome123!', 'letmein',
        'admin', 'admin123', 'admin12345', 'administrator',
        'iloveyou', 'abc123', 'abc123456', 'dragon',
        'wachtwoord', 'wachtwoord1', 'wachtwoord123',
        'passwort', 'passwort1', 'passwort123',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && in_array(mb_strtolower($value), self::PASSWORDS, true)) {
            $fail('validation.password.common')->translate();
        }
    }
}
