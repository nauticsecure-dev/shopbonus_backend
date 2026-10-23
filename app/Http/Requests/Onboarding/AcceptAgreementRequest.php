<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class AcceptAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // workspace.can:agreements.accept on the route
    }

    /**
     * `version` and `document_hash` are what the client displayed; the
     * server only accepts them if they equal the current document.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accept' => ['accepted'],
            'confirm_identity' => ['accepted'],
            'signatory_name' => ['required', 'string', 'min:2', 'max:150'],
            'version' => ['required', 'string', 'max:32'],
            'document_hash' => ['required', 'string', 'size:64'],
        ];
    }
}
