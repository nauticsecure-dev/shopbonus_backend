<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\EcommercePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Only `name` and `platform` are read; role/owner/status/webshop IDs in
     * the payload are ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'platform' => ['nullable', Rule::enum(EcommercePlatform::class)],
        ];
    }
}
