<?php

namespace App\Http\Requests\Offers;

use App\Enums\OfferAcquisition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // workspace.can:offers.manage on the route
    }

    /**
     * Amounts are integer minor units; percentages are basis points
     * (1000 = 10%). Creation requires the core terms; updates are partial.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'acquisition' => [$creating ? 'required' : 'prohibited', Rule::enum(OfferAcquisition::class)],
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'public_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'discount_type' => [$req, Rule::in(['percentage', 'fixed'])],
            'discount_value' => [$req, 'integer', 'min:1', 'max:100000000'],
            'currency' => ['sometimes', Rule::in((array) config('shopbonus.offers.currencies', ['EUR']))],
            'min_order_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'max_discount_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'price_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'claim_validity_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'redemption_validity_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'categories' => ['sometimes', 'nullable', 'array', 'max:50'],
            'categories.*' => ['string', 'max:100'],
            'products' => ['sometimes', 'nullable', 'array', 'max:200'],
            'products.*' => ['string', 'max:100'],
            'combinable' => ['sometimes', 'boolean'],
            'code_mode' => ['sometimes', Rule::in(['unique', 'reusable'])],
            'allocation_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('discount_type') === 'percentage' && (int) $this->input('discount_value') > 10000) {
                $validator->errors()->add('discount_value', 'A percentage discount is in basis points and cannot exceed 10000 (100%).');
            }
        }];
    }
}
