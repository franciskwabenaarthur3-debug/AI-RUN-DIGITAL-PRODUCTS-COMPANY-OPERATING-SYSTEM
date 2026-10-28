<?php

namespace App\Http\Controllers\Account;

use App\Exceptions\InvalidTransition;
use App\Http\Controllers\Controller;
use App\Security\Totp;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** The signed-in person's own password and two-step sign-in. */
class SecurityController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $pending = ! $user->hasTwoFactor() && $user->two_factor_secret;
        $uri = $pending ? Totp::uri(config('company_os.security.two_factor_issuer'), $user->email, $user->two_factor_secret) : null;

        return view('account.security', [
            'user' => $user,
            'pendingSecret' => $pending ? trim(chunk_split($user->two_factor_secret, 4, ' ')) : null,
            'qr' => $uri ? preg_replace('/^<\?xml[^>]*>\s*/', '', (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($uri)) : null,
            'recoveryCodes' => $request->session()->get('recovery_codes'),
            'remaining' => $this->twoFactor->remainingRecoveryCodes($user),
        ]);
    }

    public function begin(Request $request): RedirectResponse
    {
        return $this->attempt(function () use ($request) {
            $this->twoFactor->begin($request->user());

            return 'Scan the code with your authenticator app, then enter the code it shows.';
        });
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['otp' => ['required', 'string', 'max:16']]);

        return $this->attempt(function () use ($request, $data) {
            $codes = $this->twoFactor->confirm($request->user(), $data['otp']);
            session()->flash('recovery_codes', $codes);

            return 'Two-step sign-in is on. Save your recovery codes now; they are shown only once.';
        });
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $data = $request->validate(['otp' => ['required', 'string', 'max:16']]);

        return $this->attempt(function () use ($request, $data) {
            session()->flash('recovery_codes', $this->twoFactor->regenerateRecoveryCodes($request->user(), $data['otp']));

            return 'New recovery codes made. The old ones no longer work.';
        });
    }

    public function disable(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'otp' => ['required', 'string', 'max:16']]);

        return $this->attempt(function () use ($request, $data) {
            $this->twoFactor->disable($request->user(), $data['current_password'], $data['otp']);

            return 'Two-step sign-in is off.';
        });
    }

    public function password(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12), 'max:1024'],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => $data['password'], 'remember_token' => null])->save();
        $request->session()->regenerate();
        $audit->record('auth.password_changed', $user, actor: $user);

        return redirect()->route('account.security')->with('status', 'Password changed.');
    }

    private function attempt(Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (InvalidTransition $e) {
            return redirect()->route('account.security')->withErrors(['two_factor' => $e->getMessage()]);
        }

        return redirect()->route('account.security')->with('status', $message);
    }
}
