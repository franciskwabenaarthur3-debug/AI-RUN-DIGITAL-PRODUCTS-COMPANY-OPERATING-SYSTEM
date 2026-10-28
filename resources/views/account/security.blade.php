<x-layouts.app title="Account security">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Account security</h1>
        <p class="text-sm text-zinc-600">{{ $user->name }} · {{ $user->email }} · {{ $user->role->label() }}</p>
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="two-factor">
            <h2 id="two-factor" class="mb-1 flex items-center gap-2 font-semibold">Two-step sign-in
                <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $user->hasTwoFactor() ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-amber-50 text-amber-800 ring-amber-600/20' }}">{{ $user->hasTwoFactor() ? 'On' : 'Off' }}</span>
            </h2>
            <p class="mb-4 text-sm text-zinc-600">After your password, sign-in asks for a code from an authenticator app on your phone (for example Google Authenticator, Microsoft Authenticator or 1Password).@if ($user->mustUseTwoFactor()) Founder accounts must use it.@endif</p>

            @if ($recoveryCodes)
                <div role="note" class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <p class="font-medium">Recovery codes: save them somewhere safe now. They are shown only this once, and each works once if you lose your phone.</p>
                    <ul class="mt-2 grid grid-cols-2 gap-1 font-mono">@foreach ($recoveryCodes as $code)<li>{{ $code }}</li>@endforeach</ul>
                </div>
            @endif

            @if ($user->hasTwoFactor())
                <p class="text-sm">On since {{ $user->two_factor_confirmed_at->format('M j, Y') }} · {{ $remaining }} unused recovery {{ Str::plural('code', $remaining) }}</p>
                <form method="POST" action="{{ route('account.two-factor.recovery-codes') }}" class="mt-4 flex flex-wrap items-end gap-2">
                    @csrf
                    <div><label for="regen_otp" class="form-label">Code from your app</label><input id="regen_otp" name="otp" inputmode="numeric" maxlength="16" autocomplete="one-time-code" required class="form-input"></div>
                    <button type="submit" class="btn-secondary">Make new recovery codes</button>
                </form>
                @unless ($user->mustUseTwoFactor())
                    <form method="POST" action="{{ route('account.two-factor.disable') }}" class="mt-4 space-y-2 border-t border-zinc-100 pt-4">
                        @csrf
                        <h3 class="text-sm font-semibold">Turn off</h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <div><label for="disable_password" class="form-label">Password</label><input id="disable_password" name="current_password" type="password" required autocomplete="current-password" class="form-input"></div>
                            <div><label for="disable_otp" class="form-label">Code from your app</label><input id="disable_otp" name="otp" inputmode="numeric" maxlength="16" autocomplete="one-time-code" required class="form-input"></div>
                        </div>
                        <button type="submit" class="btn-secondary">Turn off two-step sign-in</button>
                    </form>
                @endunless
            @elseif ($pendingSecret)
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <div class="w-48 shrink-0 rounded-md border border-zinc-200 bg-white p-2" aria-label="QR code for your authenticator app" role="img">{!! $qr !!}</div>
                    <div class="text-sm">
                        <p>1. In your authenticator app, add an account and scan this code.</p>
                        <p class="mt-2">Or type this key: <span class="block break-all font-mono text-xs">{{ $pendingSecret }}</span></p>
                        <form method="POST" action="{{ route('account.two-factor.confirm') }}" class="mt-3 space-y-2">
                            @csrf
                            <label for="confirm_otp" class="form-label">2. Enter the code the app shows</label>
                            <input id="confirm_otp" name="otp" inputmode="numeric" maxlength="16" autocomplete="one-time-code" required autofocus class="form-input">
                            <button type="submit" class="btn-primary">Turn on</button>
                        </form>
                    </div>
                </div>
                <form method="POST" action="{{ route('account.two-factor.begin') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="text-xs text-zinc-600 underline">Start again with a new key</button>
                </form>
            @else
                <form method="POST" action="{{ route('account.two-factor.begin') }}">
                    @csrf
                    <button type="submit" class="btn-primary">Set up two-step sign-in</button>
                </form>
            @endif
        </section>

        <section class="card" aria-labelledby="password-heading">
            <h2 id="password-heading" class="mb-4 font-semibold">Change password</h2>
            <form method="POST" action="{{ route('account.password') }}" class="space-y-3">
                @csrf
                @method('PUT')
                <div><label for="current_password" class="form-label">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="form-input"></div>
                <div><label for="new_password" class="form-label">New password <span class="font-normal text-zinc-500">(at least 12 characters)</span></label><input id="new_password" name="password" type="password" required minlength="12" autocomplete="new-password" class="form-input"></div>
                <div><label for="password_confirmation" class="form-label">Repeat new password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="form-input"></div>
                <button type="submit" class="btn-secondary">Change password</button>
                <p class="text-xs text-zinc-500">"Keep me signed in" on other devices stops working.</p>
            </form>
        </section>
    </div>
</x-layouts.app>
