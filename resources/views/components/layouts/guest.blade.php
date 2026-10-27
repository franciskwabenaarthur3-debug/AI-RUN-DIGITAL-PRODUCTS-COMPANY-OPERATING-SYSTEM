@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-charcoal-950 font-sans text-zinc-900 antialiased">
    <main class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            <p class="mb-6 text-center text-lg font-semibold tracking-tight text-white">
                <span class="text-emerald-400">●</span> {{ config('app.name') }}
            </p>
            <div class="rounded-lg bg-white p-6 shadow-lg">
                {{ $slot }}
            </div>
        </div>
    </main>
</body>
</html>
