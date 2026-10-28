<?php

namespace App\Services;

use App\Exceptions\InvalidTransition;
use App\Models\User;
use App\Security\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Two-step sign-in with an authenticator app, plus single-use recovery
 * codes. Secrets are stored encrypted, recovery codes only as hashes, and
 * a code is accepted at most once.
 */
class TwoFactorService
{
    public const RECOVERY_CODES = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    /** Start (or restart) setup with a new secret. Nothing changes at sign-in until it is confirmed. */
    public function begin(User $user): User
    {
        if ($user->hasTwoFactor()) {
            throw new InvalidTransition('Two-step sign-in is already on.');
        }

        $user->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        $this->audit->record('auth.two_factor_setup_started', $user, actor: $user);

        return $user;
    }

    /**
     * Turn it on once the person shows a code from their app.
     *
     * @return list<string> recovery codes, shown once
     */
    public function confirm(User $user, string $otp): array
    {
        return DB::transaction(function () use ($user, $otp) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->hasTwoFactor() || ! $user->two_factor_secret) {
                throw new InvalidTransition('Start the setup first.');
            }
            $step = Totp::verify($user->two_factor_secret, $this->digits($otp));
            if ($step === null) {
                throw new InvalidTransition('That code is not right. Check the time on your phone and try the newest code.');
            }

            $codes = $this->newRecoveryCodes();
            $user->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_last_step' => $step,
                'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes),
            ])->save();
            $this->audit->record('auth.two_factor_enabled', $user, actor: $user);

            return $codes;
        });
    }

    /**
     * Check a sign-in code or a recovery code. Returns how it was
     * verified ("app" or "recovery"), or null.
     */
    public function verify(User $user, ?string $otp, ?string $recoveryCode): ?string
    {
        return DB::transaction(function () use ($user, $otp, $recoveryCode) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->hasTwoFactor()) {
                return null;
            }

            if (filled($otp)) {
                $step = Totp::verify($user->two_factor_secret, $this->digits($otp), $user->two_factor_last_step);
                if ($step !== null) {
                    $user->forceFill(['two_factor_last_step' => $step])->save();

                    return 'app';
                }
            }

            if (filled($recoveryCode)) {
                $hash = hash('sha256', $this->normalizeRecovery($recoveryCode));
                $remaining = $user->two_factor_recovery_codes ?? [];
                $match = array_search($hash, $remaining, true);
                if ($match !== false) {
                    unset($remaining[$match]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();
                    $this->audit->record('auth.recovery_code_used', $user, ['remaining' => count($remaining)], actor: $user);

                    return 'recovery';
                }
            }

            return null;
        });
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user, string $otp): array
    {
        if ($this->verify($user, $otp, null) === null) {
            throw new InvalidTransition('That code is not right.');
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();
        $this->audit->record('auth.recovery_codes_regenerated', $user, actor: $user);

        return $codes;
    }

    public function disable(User $user, string $password, string $otp): void
    {
        if ($user->mustUseTwoFactor()) {
            throw new InvalidTransition('Founder accounts must keep two-step sign-in on.');
        }
        if (! Hash::check($password, $user->password)) {
            throw new InvalidTransition('The password is not right.');
        }
        if ($this->verify($user, $otp, null) === null) {
            throw new InvalidTransition('That code is not right.');
        }

        $this->clear($user);
        $this->audit->record('auth.two_factor_disabled', $user, actor: $user);
    }

    /** For a lost phone: run from the server console only. */
    public function reset(User $user): void
    {
        $this->clear($user);
        $this->audit->record('auth.two_factor_reset', $user, actorType: 'console');
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    private function clear(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(fn () => strtolower(Str::random(5).'-'.Str::random(5)), range(1, self::RECOVERY_CODES));
    }

    private function digits(string $otp): string
    {
        return preg_replace('/\s+/', '', $otp);
    }

    private function normalizeRecovery(string $code): string
    {
        return strtolower(trim($code));
    }
}
