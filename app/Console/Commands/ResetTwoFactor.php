<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

/** For a person who lost their phone and their recovery codes. Needs server access. */
class ResetTwoFactor extends Command
{
    protected $signature = 'company-os:reset-two-factor {email}';

    protected $description = 'Turn off two-step sign-in for an account (lost phone and recovery codes)';

    public function handle(TwoFactorService $twoFactor): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        if (! $this->confirm("Turn off two-step sign-in for {$user->email}? Check it is really them first.")) {
            return self::FAILURE;
        }

        $twoFactor->reset($user);
        $this->info('Done. They sign in with their password and must set up two-step sign-in again'.($user->mustUseTwoFactor() ? ' before doing anything else.' : '.'));

        return self::SUCCESS;
    }
}
