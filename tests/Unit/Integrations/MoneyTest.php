<?php

namespace Tests\Unit\Integrations;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return list<array{string, string, int}> */
    public static function conversions(): array
    {
        return [['25.99', 'EUR', 2599], ['25.9', 'EUR', 2590], ['25', 'EUR', 2500], ['0.00', 'EUR', 0], ['25.990', 'EUR', 2599],
            ['1500', 'JPY', 1500], ['1.234', 'KWD', 1234], ['-5.00', 'EUR', -500], ['0.10', 'EUR', 10], ['123456789.01', 'EUR', 12345678901]];
    }

    #[DataProvider('conversions')]
    public function test_exact_decimal_to_minor_units(string $amount, string $currency, int $expected): void
    {
        $this->assertSame($expected, Money::toMinor($amount, $currency));
    }

    /** @return list<array{string, string}> */
    public static function invalid(): array
    {
        return [['25.999', 'EUR'], ['1.5', 'JPY'], ['abc', 'EUR'], ['1e3', 'EUR'], ['', 'EUR'], ['1,50', 'EUR'], ['9999999999999999', 'EUR']];
    }

    #[DataProvider('invalid')]
    public function test_rejects_instead_of_rounding(string $amount, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMinor($amount, $currency);
    }

    public function test_currency_codes(): void
    {
        $this->assertTrue(Money::isCurrency('EUR'));
        $this->assertFalse(Money::isCurrency('eur'));
        $this->assertFalse(Money::isCurrency('EURO'));
        $this->assertFalse(Money::isCurrency(null));
    }
}
