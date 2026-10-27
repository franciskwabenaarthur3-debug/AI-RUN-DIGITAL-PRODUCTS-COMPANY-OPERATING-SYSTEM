<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts between decimal strings and integer minor units without floats.
 *
 * Only two-decimal currencies are supported; see config('company_os.currencies').
 */
final class Money
{
    public static function toMinor(string $amount): int
    {
        $amount = trim($amount);

        if (! preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $amount, $m)) {
            throw new InvalidArgumentException('Amount must be a non-negative number with at most two decimals.');
        }

        $fraction = str_pad($m[2] ?? '', 2, '0');

        return (int) $m[1] * 100 + (int) $fraction;
    }

    public static function fromMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minor, string $currency): string
    {
        $major = self::fromMinor($minor);
        [$whole, $fraction] = explode('.', ltrim($major, '-'));

        return ($minor < 0 ? '-' : '').$currency.' '.number_format((int) $whole).'.'.$fraction;
    }
}
