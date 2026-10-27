<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function conversions(): array
    {
        return [
            ['0', 0],
            ['7', 700],
            ['0.1', 10],
            ['0.01', 1],
            ['150.50', 15050],
            ['19.99', 1999],
            ['1234567.89', 123456789],
        ];
    }

    #[DataProvider('conversions')]
    public function test_converts_decimal_strings_to_minor_units_exactly(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::toMinor($input));
    }

    public function test_round_trips_without_float_drift(): void
    {
        // 0.1 + 0.2 is the classic float trap.
        $this->assertSame('0.30', Money::fromMinor(Money::toMinor('0.1') + Money::toMinor('0.2')));
    }

    public static function invalidAmounts(): array
    {
        return [['-1'], ['1.234'], ['abc'], [''], ['1,000'], ['1e3']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMinor($input);
    }

    public function test_formats_for_display(): void
    {
        $this->assertSame('USD 1,234.05', Money::format(123405, 'USD'));
        $this->assertSame('-GHS 0.50', Money::format(-50, 'GHS'));
    }
}
