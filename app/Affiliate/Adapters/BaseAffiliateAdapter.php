<?php

namespace App\Affiliate\Adapters;

use App\Affiliate\AffiliateNetworkAdapter;
use App\Models\AffiliateProgram;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Shared helpers for network adapters.
 */
abstract class BaseAffiliateAdapter implements AffiliateNetworkAdapter
{
    public function trackingUrl(AffiliateProgram $program, string $clickReference): string
    {
        // The template is the network's own link; only our placeholder is filled in.
        return str_replace('{click_ref}', rawurlencode($clickReference), $program->tracking_url_template);
    }

    /**
     * Network amounts arrive as decimals (often JSON numbers). Converted
     * exactly; extra decimals beyond the currency's are truncated toward
     * zero, so a reward is never computed on more than was earned.
     */
    protected function minor(mixed $amount, string $currency): int
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Amount is not numeric.');
        }
        $string = is_float($amount) ? rtrim(rtrim(sprintf('%.6F', $amount), '0'), '.') : trim((string) $amount);
        $exponent = Money::exponent($currency);
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', $string, $m) === 1 && isset($m[2]) && strlen($m[2]) > $exponent) {
            $string = $m[1].($exponent > 0 ? '.'.substr($m[2], 0, $exponent) : '');
        }

        return abs(Money::toMinor($string, $currency));
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }

    /** Percentage as basis points from a decimal like "6.5" (= 6.5%). */
    protected function percentBps(mixed $percent): int
    {
        return $this->minor($percent, 'EUR'); // two decimals of a percentage are basis points
    }
}
