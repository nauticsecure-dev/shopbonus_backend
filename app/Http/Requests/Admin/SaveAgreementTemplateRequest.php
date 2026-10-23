<?php

namespace App\Http\Requests\Admin;

use App\Models\AgreementTemplate;
use App\Models\User;
use App\Services\Agreements\AgreementService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAgreementTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // platform:super_admin on the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'key' => [$required, Rule::in([AgreementTemplate::MERCHANT])],
            'version' => [$required, 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'locale' => [$required, Rule::in(User::SUPPORTED_LOCALES)],
            'title' => [$required, 'string', 'max:255'],
            'body' => [$required, 'string', 'max:200000', function (string $attr, mixed $value, Closure $fail) {
                preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', (string) $value, $m);
                $unknown = array_diff($m[1], AgreementService::PLACEHOLDERS);
                if ($unknown !== []) {
                    $fail('Unknown placeholders: '.implode(', ', array_unique($unknown)).'. Allowed: '.implode(', ', AgreementService::PLACEHOLDERS).'.');
                }
            }],
            'requires_reacceptance' => ['sometimes', 'boolean'],
        ];
    }
}
