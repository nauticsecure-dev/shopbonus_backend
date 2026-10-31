<?php

namespace App\Services\Offers;

/**
 * Suggested starting values per discovery category (Trello #9 §4).
 * Suggestions only — merchants may change any permitted setting.
 */
class BranchOfferDefaults
{
    /**
     * @param  list<string>  $categoryKeys
     * @return array{
     *   category: string|null,
     *   suggestion: array{
     *     discount_type: string,
     *     discount_value: int,
     *     min_order_minor: int,
     *     max_discount_minor: int,
     *     claim_validity_days: int,
     *     redemption_validity_days: int,
     *     note: string
     *   },
     *   rationale: string
     * }
     */
    public function forCategories(array $categoryKeys): array
    {
        $map = (array) config('shopbonus.offers.branch_defaults', []);
        $key = null;
        foreach ($categoryKeys as $candidate) {
            if (isset($map[$candidate]) && $candidate !== 'default') {
                $key = $candidate;
                break;
            }
        }
        $key ??= $categoryKeys[0] ?? null;
        $defaults = $map[$key] ?? $map['default'] ?? [
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'min_order_minor' => 10000,
            'max_discount_minor' => 1000,
            'claim_validity_days' => 90,
            'redemption_validity_days' => 90,
            'note' => 'Balanced starting point for most webshops.',
        ];

        return [
            'category' => $key,
            'suggestion' => $defaults,
            'rationale' => (string) ($defaults['note'] ?? ''),
        ];
    }
}
