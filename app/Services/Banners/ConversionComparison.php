<?php

namespace App\Services\Banners;

/** Exploratory comparison of independent assigned-visitor Bernoulli outcomes. */
class ConversionComparison
{
    /** @return array<string, mixed> */
    public function calculate(int $controlVisitors, int $controlConverted, int $variantVisitors, int $variantConverted): array
    {
        if ($controlVisitors < 0 || $variantVisitors < 0 || $controlConverted < 0 || $variantConverted < 0) {
            throw new \InvalidArgumentException('Conversions must be a subset of assigned visitors.');
        }

        $result = ['status' => 'insufficient_data', 'method' => 'two_proportion_z_test_wald_interval', 'confidence_level' => 0.95,
            'absolute_difference' => null, 'relative_change' => null, 'confidence_interval' => null, 'p_value' => null,
            'statistically_significant' => null, 'favored_arm' => null, 'winner' => null,
            'winner_status' => 'blocked_unverified_measurement_and_analysis_plan'];
        // Report queries can straddle a new assignment/payment. Never infer from an inconsistent observation.
        if ($controlConverted > $controlVisitors || $variantConverted > $variantVisitors) {
            $result['status'] = 'inconsistent_observation';

            return $result;
        }
        if ($controlVisitors === 0 || $variantVisitors === 0) {
            return $result;
        }

        $control = $controlConverted / $controlVisitors;
        $variant = $variantConverted / $variantVisitors;
        $difference = $variant - $control;
        $result['absolute_difference'] = round($difference, 8);
        $result['relative_change'] = $controlConverted === 0 ? null : round($difference / $control, 8);
        // Normal approximation requires at least ten successes AND failures per arm.
        if (min($controlConverted, $variantConverted, $controlVisitors - $controlConverted, $variantVisitors - $variantConverted) < 10) {
            return $result;
        }

        $pooled = ($controlConverted + $variantConverted) / ($controlVisitors + $variantVisitors);
        $standardError = sqrt($pooled * (1 - $pooled) * (1 / $controlVisitors + 1 / $variantVisitors));
        $z = abs($difference) / $standardError;
        $p = $this->twoSidedNormalTail($z);
        $margin = 1.959963984540054 * sqrt($control * (1 - $control) / $controlVisitors + $variant * (1 - $variant) / $variantVisitors);
        $result['status'] = 'exploratory';
        $result['confidence_interval'] = ['lower' => round(max(-1, $difference - $margin), 8), 'upper' => round(min(1, $difference + $margin), 8)];
        $result['p_value'] = round($p, 8);
        $result['statistically_significant'] = $p < 0.05;
        $result['favored_arm'] = $p >= 0.05 ? null : ($difference > 0 ? 'variant' : 'control');

        return $result;
    }

    private function twoSidedNormalTail(float $z): float
    {
        // Abramowitz-Stegun 26.2.17, absolute CDF error < 7.5e-8.
        $t = 1 / (1 + 0.2316419 * $z);
        $polynomial = $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));

        return min(1.0, max(0.0, 2 * exp(-$z * $z / 2) / sqrt(2 * M_PI) * $polynomial));
    }
}
