<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\s*\d{6}\s*$/'],
        ];
    }

    public function messages(): array
    {
        return ['code.regex' => 'Enter the 6-digit code from the email.'];
    }
}
