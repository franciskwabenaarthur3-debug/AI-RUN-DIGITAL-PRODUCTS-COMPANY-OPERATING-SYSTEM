<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        $credentials = [
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'is_active' => true,
        ];

        if (! Auth::validate($credentials)) {
            $request->hitRateLimiter();
            $this->audit->record('auth.login_failed', metadata: [
                'email_hash' => hash('sha256', strtolower($credentials['email'])),
            ]);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $request->clearRateLimiter();
        $request->session()->regenerate();
        /** @var User $user */
        $user = Auth::getLastAttempted();

        // The password is right; with two-step sign-in on, the session is
        // only logged in after the code (TwoFactorChallengeController).
        if ($user->hasTwoFactor()) {
            $request->session()->put('two_factor_login', [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes((int) config('company_os.security.pending_login_minutes'))->timestamp,
            ]);

            return redirect()->route('two-factor.challenge');
        }

        return self::completeLogin($request, $user, $request->boolean('remember'), $this->audit);
    }

    public static function completeLogin(Request $request, User $user, bool $remember, AuditLogger $audit, ?string $method = null): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record('auth.login', $user, array_filter(['two_factor' => $method]), actor: $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            $this->audit->record('auth.logout', $user, actor: $user);
        }

        return redirect()->route('login');
    }
}
