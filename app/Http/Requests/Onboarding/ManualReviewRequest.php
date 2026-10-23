<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

class ManualReviewRequest extends FormRequest
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
            'notes' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence' => ['sometimes', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
        ];
    }
}
