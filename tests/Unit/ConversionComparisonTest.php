<?php

namespace Tests\Unit;

use App\Services\Banners\ConversionComparison;
use PHPUnit\Framework\TestCase;

class ConversionComparisonTest extends TestCase
{
    public function test_known_positive_difference_and_reverse_are_symmetric(): void
    {
        $service = new ConversionComparison;
        $a = $service->calculate(1000, 100, 1000, 150);
        $b = $service->calculate(1000, 150, 1000, 100);
        $this->assertSame('exploratory', $a['status']);
        $this->assertSame(0.05, $a['absolute_difference']);
        $this->assertSame(0.5, $a['relative_change']);
        $this->assertEqualsWithDelta(0.00072323, $a['p_value'], 0.000001);
        $this->assertEqualsWithDelta(0.02109495, $a['confidence_interval']['lower'], 0.000001);
        $this->assertEqualsWithDelta(0.07890505, $a['confidence_interval']['upper'], 0.000001);
        $this->assertTrue($a['statistically_significant']);
        $this->assertSame('variant', $a['favored_arm']);
        $this->assertSame('control', $b['favored_arm']);
        $this->assertSame($a['p_value'], $b['p_value']);
        $this->assertSame(-$a['confidence_interval']['upper'], $b['confidence_interval']['lower']);
        $this->assertNull($a['winner']);
    }

    public function test_equal_rates_with_unequal_allocation_do_not_favor_either_arm(): void
    {
        $a = (new ConversionComparison)->calculate(100, 10, 900, 90);
        $this->assertSame(0.0, $a['absolute_difference']);
        $this->assertSame(1.0, $a['p_value']);
        $this->assertFalse($a['statistically_significant']);
        $this->assertNull($a['favored_arm']);
        $this->assertLessThan(0, $a['confidence_interval']['lower']);
        $this->assertGreaterThan(0, $a['confidence_interval']['upper']);
    }

    public function test_empty_sparse_zero_baseline_and_all_converted_are_not_significance_claims(): void
    {
        $service = new ConversionComparison;
        foreach ([[0, 0, 100, 20], [1000, 0, 1000, 15], [100, 9, 100, 20], [100, 100, 100, 100], [10, 5, 10, 5]] as $counts) {
            $a = $service->calculate(...$counts);
            $this->assertSame('insufficient_data', $a['status']);
            $this->assertNull($a['p_value']);
            $this->assertNull($a['confidence_interval']);
            $this->assertNull($a['statistically_significant']);
            $this->assertNull($a['winner']);
        }
        $a = $service->calculate(1000, 0, 1000, 15);
        $this->assertSame(0.015, $a['absolute_difference']);
        $this->assertNull($a['relative_change']);
    }

    public function test_impossible_visitor_counts_fail_closed(): void
    {
        $result = (new ConversionComparison)->calculate(10, 11, 20, 10);
        $this->assertSame('inconsistent_observation', $result['status']);
        $this->assertNull($result['absolute_difference']);
        $this->assertNull($result['p_value']);
        $this->assertNull($result['winner']);
    }

    public function test_negative_counts_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConversionComparison)->calculate(-1, 0, 20, 10);
    }
}
