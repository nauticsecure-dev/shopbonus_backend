<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class WebshopDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // platform:super_admin + password.recent on the route
    }

    /**
     * A reason is mandatory for reject/suspend/unsuspend (it is shown to the
     * merchant as status_reason) and optional for approval.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $approve = $this->route()?->getName() === 'api.v1.admin.webshops.approve';

        return [
            'reason' => [$approve ? 'nullable' : 'required', 'string', 'min:3', 'max:500'],
            'confirm_duplicate_claim' => ['sometimes', 'boolean'],
        ];
    }
}
