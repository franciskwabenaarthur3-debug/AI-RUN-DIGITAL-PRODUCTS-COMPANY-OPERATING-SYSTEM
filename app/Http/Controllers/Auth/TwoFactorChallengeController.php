<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Second step of signing in. The password was checked by SessionController;
 * the pending sign-in lives in the session for a few minutes and is
 * dropped after too many wrong codes.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor, private readonly AuditLogger $audit) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login')->withErrors(['email' => 'Sign in again.']);
        }

        return view('auth.two-factor');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'otp' => ['nullable', 'string', 'max:16', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Sign in again.']);
        }

        $key = 'two-factor|'.$user->id;
        $method = $this->twoFactor->verify($user, $data['otp'] ?? null, $data['recovery_code'] ?? null);

        if ($method === null) {
            RateLimiter::hit($key, 300);
            $this->audit->record('auth.two_factor_failed', $user, actorType: 'system');
            if (RateLimiter::tooManyAttempts($key, (int) config('company_os.security.two_factor_max_attempts'))) {
                $request->session()->forget('two_factor_login');

                return redirect()->route('login')->withErrors(['email' => 'Too many wrong codes. Sign in again in a few minutes.']);
            }

            throw ValidationException::withMessages(['otp' => 'That code is not right.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('two_factor_login.remember');
        $request->session()->forget('two_factor_login');

        return SessionController::completeLogin($request, $user, $remember, $this->audit, $method);
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('two_factor_login');
        if (! $pending || $pending['expires_at'] < now()->timestamp) {
            $request->session()->forget('two_factor_login');

            return null;
        }
        if (RateLimiter::tooManyAttempts('two-factor|'.$pending['user_id'], (int) config('company_os.security.two_factor_max_attempts'))) {
            $request->session()->forget('two_factor_login');

            return null;
        }

        return User::query()->whereKey($pending['user_id'])->where('is_active', true)->first();
    }
}
