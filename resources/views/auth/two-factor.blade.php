<x-layouts.guest title="Two-step sign-in">
    <h1 class="mb-1 text-xl font-semibold">Two-step sign-in</h1>
    <p class="mb-6 text-sm text-zinc-600">Enter the six-digit code from your authenticator app.</p>

    <form method="POST" action="{{ route('two-factor.store') }}" class="space-y-4" novalidate>
        @csrf
        <div>
            <label for="otp" class="form-label">Code</label>
            <input id="otp" name="otp" inputmode="numeric" pattern="[0-9 ]*" maxlength="16" autocomplete="one-time-code" autofocus
                   class="form-input" @error('otp') aria-invalid="true" aria-describedby="otp-error" @enderror>
            @error('otp')
                <p id="otp-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <details class="text-sm">
            <summary class="cursor-pointer text-zinc-700">Lost your phone? Use a recovery code</summary>
            <div class="mt-2">
                <label for="recovery_code" class="form-label">Recovery code</label>
                <input id="recovery_code" name="recovery_code" maxlength="32" autocomplete="off" class="form-input" placeholder="xxxxx-xxxxx">
                <p class="mt-1 text-xs text-zinc-500">Each recovery code works once.</p>
            </div>
        </details>
        <button type="submit" class="btn-primary w-full">Continue</button>
    </form>
    <p class="mt-4 text-center text-sm"><a href="{{ route('login') }}" class="text-emerald-700 underline">Start again</a></p>
</x-layouts.guest>
