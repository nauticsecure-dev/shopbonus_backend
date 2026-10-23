<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // workspace.can:ownership.manage on the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['dns_txt', 'website_file', 'domain_email'])],
            'mailbox' => ['required_if:method,domain_email', 'nullable', 'string', 'max:64'],
        ];
    }
}
