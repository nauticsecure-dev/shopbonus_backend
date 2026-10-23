<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // workspace.can:team.manage on the route; authority rules in TeamService
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(MembershipRole::class)],
            'permissions' => ['sometimes', 'array', 'max:30'],
            'permissions.*' => ['string', 'max:64'],
        ];
    }
}
