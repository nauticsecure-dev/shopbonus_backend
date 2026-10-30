<?php

namespace App\Http\Requests\Offers;

use App\Enums\OfferAcquisition;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Services\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // workspace.can:offers.manage on the route
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('discount_type') === 'free_shipping') {
            $this->merge(['discount_value' => 0]);
        }
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
            'discount_type' => [$req, Rule::in(['percentage', 'fixed', 'free_shipping'])],
            'discount_value' => [$this->input('discount_type') === 'free_shipping' ? 'sometimes' : $req, 'integer', 'min:'.($this->input('discount_type') === 'free_shipping' ? 0 : 1), 'max:100000000'],
            'currency' => ['sometimes', Rule::in((array) config('shopbonus.offers.currencies', ['EUR']))],
            'min_order_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'max_discount_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'price_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', ...($this->filled('starts_at') ? ['after:starts_at'] : [])],
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
            if (! $this->exists('starts_at') && ! $this->exists('ends_at')) {
                return;
            }
            $starts = $this->exists('starts_at') ? $this->input('starts_at') : null;
            $ends = $this->exists('ends_at') ? $this->input('ends_at') : null;
            if (! $this->isMethod('POST') && (! $this->exists('starts_at') || ! $this->exists('ends_at'))) {
                $stored = $this->storedWindow();
                $starts = $this->exists('starts_at') ? $starts : $stored['starts_at'];
                $ends = $this->exists('ends_at') ? $ends : $stored['ends_at'];
            }
            if ($starts && $ends && CarbonImmutable::parse((string) $ends)->lessThanOrEqualTo(CarbonImmutable::parse((string) $starts))) {
                $validator->errors()->add('ends_at', 'The end must be after the start.');
            }
        }];
    }

    /** @return array{starts_at: string|null, ends_at: string|null} */
    private function storedWindow(): array
    {
        $publicId = $this->route('offer');
        $current = app(CurrentWorkspace::class);
        // ResolveWorkspace removes {workspace} from the route after it binds the shop.
        if (! is_string($publicId) || ! Str::isUlid($publicId) || ! $current->isResolved()) {
            return ['starts_at' => null, 'ends_at' => null];
        }

        $version = OfferVersion::query()
            ->whereIn('id', Offer::query()->where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->select('current_version_id'))
            ->first(['starts_at', 'ends_at']);

        return ['starts_at' => $version?->starts_at?->toIso8601String(), 'ends_at' => $version?->ends_at?->toIso8601String()];
    }
}
