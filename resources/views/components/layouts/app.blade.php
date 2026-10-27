@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-50 font-sans text-zinc-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:rounded focus:bg-white focus:px-3 focus:py-2">Skip to content</a>

    @php($company = \App\Models\CompanySetting::current())

    <div class="lg:flex">
        {{-- Desktop sidebar --}}
        <aside class="hidden w-60 shrink-0 bg-charcoal-900 lg:fixed lg:inset-y-0 lg:flex lg:flex-col">
            <div class="px-5 py-5 text-base font-semibold tracking-tight text-white">
                <span class="text-emerald-400">●</span> {{ config('app.name') }}
            </div>
            <div class="flex-1 px-3">
                @include('components.layouts.navigation')
            </div>
            <div class="border-t border-charcoal-700 px-5 py-4 text-sm text-zinc-300">
                <p class="truncate font-medium text-white">{{ auth()->user()->name }}</p>
                <p class="text-xs text-zinc-400">{{ auth()->user()->role->label() }}</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="text-sm text-emerald-300 hover:text-emerald-200">Log out</button>
                </form>
            </div>
        </aside>

        {{-- Mobile header --}}
        <header class="bg-charcoal-900 px-4 py-3 text-white lg:hidden">
            <details class="group">
                <summary class="flex cursor-pointer list-none items-center justify-between">
                    <span class="font-semibold"><span class="text-emerald-400">●</span> {{ config('app.name') }}</span>
                    <span class="rounded border border-charcoal-700 px-2 py-1 text-sm">Menu</span>
                </summary>
                <div class="mt-3 space-y-3">
                    @include('components.layouts.navigation')
                    <form method="POST" action="{{ route('logout') }}" class="px-3">
                        @csrf
                        <button type="submit" class="text-sm text-emerald-300">Log out ({{ auth()->user()->name }})</button>
                    </form>
                </div>
            </details>
        </header>

        <div class="flex-1 lg:pl-60">
            @if ($company->isPaused())
                <div role="alert" class="bg-amber-100 px-4 py-2 text-sm font-medium text-amber-900 sm:px-8">
                    Emergency pause is on. Company operations are marked as paused.
                </div>
            @endif
            <main id="main" class="mx-auto max-w-6xl px-4 py-8 sm:px-8">
                @if (session('status'))
                    <div role="status" class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        {{ session('status') }}
                    </div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
