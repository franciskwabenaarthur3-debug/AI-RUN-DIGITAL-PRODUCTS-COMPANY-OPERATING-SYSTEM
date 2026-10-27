<x-layouts.guest title="Log in">
    <h1 class="mb-1 text-xl font-semibold">Log in</h1>
    <p class="mb-6 text-sm text-zinc-600">Accounts are created by an administrator.</p>

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4" novalidate>
        @csrf
        <div>
            <label for="email" class="form-label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="form-input" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')
                <p id="email-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label for="password" class="form-label">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="form-input"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password')
                <p id="password-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <label class="flex items-center gap-2 text-sm text-zinc-700">
            <input type="checkbox" name="remember" value="1" class="rounded border-zinc-300 text-emerald-700">
            Keep me signed in
        </label>
        <button type="submit" class="btn-primary w-full">Log in</button>
    </form>
</x-layouts.guest>
