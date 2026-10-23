<?php

namespace App\Http\Requests\Workspaces;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The invitation token travels in the body (never the URL) so it does not
 * end up in access logs or Referer headers.
 */
class InvitationTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:48']];
    }
}
