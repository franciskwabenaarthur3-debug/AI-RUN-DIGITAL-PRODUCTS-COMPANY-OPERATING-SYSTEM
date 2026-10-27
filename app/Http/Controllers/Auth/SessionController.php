<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
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

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->hitRateLimiter();
            $this->audit->record('auth.login_failed', metadata: [
                'email_hash' => hash('sha256', strtolower($credentials['email'])),
            ]);

            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $request->clearRateLimiter();
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user);

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
