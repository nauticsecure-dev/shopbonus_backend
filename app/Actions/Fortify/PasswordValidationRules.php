<?php

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Password::defaults() is configured in AppServiceProvider.
     *
     * Registration and reset keep a single password field (reveal toggle,
     * D-014). Authenticated password *changes* require confirmation so a
     * direct API call cannot set a new password without matching
     * `password_confirmation` (Trello #10).
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(bool $confirmed = false): array
    {
        $rules = ['required', 'string', Password::default()];
        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }
}
