<?php

namespace Tests\Unit;

use App\Security\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"

    public function test_codes_match_the_rfc_6238_test_vectors(): void
    {
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::code(self::RFC_SECRET, Totp::step($time)));
        }
    }

    public function test_verify_allows_one_step_of_drift_and_refuses_reuse(): void
    {
        $time = 1234567890;
        $step = Totp::step($time);
        $previous = Totp::code(self::RFC_SECRET, $step - 1);

        $this->assertSame($step - 1, Totp::verify(self::RFC_SECRET, $previous, time: $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 2), time: $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, $previous, after: $step - 1, time: $time), 'A used step is refused.');
        $this->assertNull(Totp::verify(self::RFC_SECRET, '12345', time: $time));
        $this->assertNull(Totp::verify(self::RFC_SECRET, 'abcdef', time: $time));
    }

    public function test_secrets_are_random_base32_and_the_uri_is_for_authenticator_apps(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, Totp::generateSecret());
        $this->assertSame(6, strlen(Totp::code($secret, 1)));
        $this->assertStringStartsWith('otpauth://totp/Company%20OS:a%40b.example?secret='.$secret, Totp::uri('Company OS', 'a@b.example', $secret));
    }
}
