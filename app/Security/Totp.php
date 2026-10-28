<?php

namespace App\Security;

/**
 * Time-based one-time passwords (RFC 6238: HMAC-SHA1, 30-second steps,
 * six digits), the format authenticator apps use.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The step the code belongs to, allowing one step of clock drift either
     * way, or null. Steps at or before $after are refused, so a code cannot
     * be used twice.
     */
    public static function verify(string $secret, string $code, ?int $after = null, ?int $time = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $now = self::step($time);
        foreach ([$now - 1, $now, $now + 1] as $step) {
            if (($after === null || $step > $after) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return "otpauth://totp/{$label}?".http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    private static function base32Decode(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($text, '='))) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('The secret is not base32.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }
}
