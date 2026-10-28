@php
    $tone = fn ($s) => match ($s) { 'ok' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'warning' => 'bg-amber-50 text-amber-800 ring-amber-600/20', default => 'bg-red-50 text-red-800 ring-red-600/20' };
    $label = fn ($s) => match ($s) { 'ok' => 'OK', 'warning' => 'Check', default => 'Failed' };
@endphp
<x-layouts.app title="System">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">System</h1>
        <p class="text-sm text-zinc-600">Whether the background processes and backups Company OS depends on are working. Problems other than the scheduler also raise alerts. A stopped scheduler cannot report itself, so also watch <span class="font-mono">/up</span> with an external uptime monitor.</p>
    </div>

    <section class="card mb-6" aria-labelledby="health">
        <h2 id="health" class="mb-3 font-semibold">Health</h2>
        <ul class="divide-y divide-zinc-100 text-sm">
            @foreach ($checks as $check)
                <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:gap-4">
                    <span class="w-40 shrink-0 font-medium">{{ $check['label'] }}</span>
                    <span class="inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $tone($check['status']) }}">{{ $label($check['status']) }}</span>
                    <span class="text-zinc-600">{{ $check['detail'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="card mb-6" aria-labelledby="backups">
        <h2 id="backups" class="mb-3 font-semibold">Recent backups</h2>
        @if ($backups->isEmpty())
            <p class="text-sm text-zinc-600">None yet. The scheduler makes one every night at 02:00; run <span class="font-mono">php artisan company-os:backup</span> to make one now.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead class="text-xs text-zinc-500"><tr><th class="py-1 font-medium">Started</th><th class="py-1 font-medium">File</th><th class="py-1 font-medium">Size</th><th class="py-1 font-medium">Result</th><th class="py-1 font-medium">Restore test</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach ($backups as $b)
                            <tr>
                                <td class="py-1.5">{{ $b->started_at->format('M j, H:i') }}</td>
                                <td class="py-1.5 break-all font-mono text-xs">{{ $b->filename }}</td>
                                <td class="py-1.5">{{ $b->size_bytes ? number_format($b->size_bytes / 1024, 0).' KB' : '—' }}</td>
                                <td class="py-1.5">{{ ucfirst($b->status) }}@if ($b->error)<span class="block text-xs text-red-700">{{ Str::limit($b->error, 120) }}</span>@endif</td>
                                <td class="py-1.5">{{ $b->verify_status ? ucfirst($b->verify_status).' '.$b->verified_at->format('M j') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($readiness !== null)
        <section class="card mb-6" aria-labelledby="readiness">
            <h2 id="readiness" class="mb-1 font-semibold">Pilot readiness</h2>
            <p class="mb-3 text-sm text-zinc-600">The same list as <span class="font-mono">php artisan company-os:readiness</span>. Every item must be OK or understood before real clients use the system.</p>
            <ul class="divide-y divide-zinc-100 text-sm">
                @foreach ($readiness as $item)
                    <li class="flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:gap-4">
                        <span class="inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $tone($item['status']) }}">{{ $label($item['status']) }}</span>
                        <span class="font-medium">{{ $item['label'] }}</span>
                        <span class="text-zinc-600">{{ $item['detail'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="card" aria-labelledby="people">
                <h2 id="people" class="mb-3 font-semibold">Who can sign in</h2>
                <ul class="divide-y divide-zinc-100 text-sm">
                    @foreach ($permissions['users'] as $u)
                        <li class="py-2"><span class="font-medium">{{ $u->name }}</span> <span class="text-zinc-500">{{ $u->email }}</span>
                            <span class="block text-xs text-zinc-600">{{ $u->role->label() }} · {{ $u->is_active ? 'active' : 'deactivated' }} · two-step {{ $u->two_factor_confirmed_at ? 'on' : 'off' }} · last sign-in {{ $u->last_login_at?->format('M j, Y') ?? 'never' }}</span></li>
                    @endforeach
                </ul>
            </section>
            <section class="card" aria-labelledby="agent-perms">
                <h2 id="agent-perms" class="mb-3 font-semibold">What agents may do</h2>
                <ul class="divide-y divide-zinc-100 text-sm">
                    @foreach ($permissions['agents'] as $a)
                        <li class="py-2"><a href="{{ route('agents.show', $a) }}" class="font-medium underline-offset-2 hover:underline">{{ $a->name }}</a> <x-status-badge :status="$a->status" />
                            <span class="block text-xs text-zinc-600">Runtime {{ $a->runtime }} · {{ $a->permissions->isEmpty() ? 'no tools granted' : $a->permissions->pluck('tool_name')->implode(', ') }}</span></li>
                    @endforeach
                </ul>
            </section>
        </div>
    @endif
</x-layouts.app>
