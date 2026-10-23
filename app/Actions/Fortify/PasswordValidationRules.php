<?php

namespace App\Actions\Fortify;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Password::defaults() is configured in AppServiceProvider. No
     * confirmation field: the SPA uses a reveal toggle instead (Trello #4).
     *
     * @return array<int, Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', 'max:255', Password::default()];
    }
}
