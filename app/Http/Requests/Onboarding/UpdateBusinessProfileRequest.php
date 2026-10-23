<?php

namespace App\Http\Requests\Onboarding;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // workspace.can:settings.manage on the route
    }

    /**
     * Every field is optional so drafts can be saved; `confirm: true`
     * requires the full set (checked by BusinessProfileService).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $digitsOnly = fn (string $v) => preg_replace('/[\s.\-]/', '', $v) ?? '';

        return [
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trading_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'kvk_number' => ['sometimes', 'nullable', 'string', 'max:20', function (string $attr, mixed $value, Closure $fail) use ($digitsOnly) {
                if ($value !== null && preg_match('/^\d{8}$/', $digitsOnly((string) $value)) !== 1) {
                    $fail('The KvK number must have 8 digits.');
                }
            }],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:20', function (string $attr, mixed $value, Closure $fail) use ($digitsOnly) {
                $vat = strtoupper($digitsOnly((string) $value));
                $valid = str_starts_with($vat, 'NL')
                    ? preg_match('/^NL\d{9}B\d{2}$/', $vat) === 1
                    : preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $vat) === 1;
                if ($value !== null && ! $valid) {
                    $fail('Enter a valid EU VAT number (for example NL123456789B01).');
                }
            }],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9 ()\-]{6,20}$/'],
            'main_contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'preferred_language' => ['sometimes', Rule::in(User::SUPPORTED_LOCALES)],
            'google_place_id' => ['sometimes', 'nullable', 'string', 'max:300'],
            'business_category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'source' => ['sometimes', Rule::in(['manual', 'google'])],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }
}
