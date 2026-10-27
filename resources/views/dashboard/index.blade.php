<x-layouts.app title="Dashboard">
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $settings->company_name }}</h1>
        <p class="text-sm text-zinc-600">Executive dashboard. Figures shown here come only from recorded data.</p>
    </div>

    <section aria-labelledby="overview" class="mb-8">
        <h2 id="overview" class="sr-only">Overview</h2>
        <dl class="grid gap-4 sm:grid-cols-3">
            <div class="card">
                <dt class="text-sm text-zinc-600">Operating status</dt>
                <dd class="mt-1 text-lg font-semibold {{ $settings->isPaused() ? 'text-amber-700' : 'text-emerald-700' }}">
                    {{ $settings->operating_status->label() }}
                </dd>
            </div>
            <div class="card">
                <dt class="text-sm text-zinc-600">Monthly AI budget (limit)</dt>
                <dd class="mt-1 text-lg font-semibold">{{ $aiBudget }}</dd>
                <dd class="text-xs text-zinc-500">Spending tracking arrives with the agent infrastructure.</dd>
            </div>
            <div class="card">
                <dt class="text-sm text-zinc-600">Active service packages</dt>
                <dd class="mt-1 text-lg font-semibold">{{ $activePackages }}</dd>
            </div>
        </dl>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="getting-started" class="card">
            <h2 id="getting-started" class="mb-3 font-semibold">Getting started</h2>
            <ol class="space-y-2 text-sm">
                <li class="flex gap-2">
                    <span aria-hidden="true">{{ $settingsReviewed ? '✓' : '○' }}</span>
                    <span>Review company settings, currency and AI budget on the <a href="{{ route('settings.edit') }}" class="font-medium text-emerald-700 underline">Settings</a> page.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true">{{ $activePackages > 0 ? '✓' : '○' }}</span>
                    <span>Define service packages with real scope and pricing (package management is the next build phase).</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true">○</span>
                    <span>CRM, agents, approvals, proposals and projects are added in later phases and will appear in the menu when they work.</span>
                </li>
            </ol>
        </section>

        @if ($recentAudit !== null)
            <section aria-labelledby="recent-audit" class="card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 id="recent-audit" class="font-semibold">Recent audit events</h2>
                    <a href="{{ route('audit.index') }}" class="text-sm font-medium text-emerald-700 underline">View all</a>
                </div>
                @if ($recentAudit->isEmpty())
                    <p class="text-sm text-zinc-600">No audit events recorded yet.</p>
                @else
                    <ul class="divide-y divide-zinc-100 text-sm">
                        @foreach ($recentAudit as $log)
                            <li class="flex justify-between gap-4 py-2">
                                <span class="font-mono text-xs text-zinc-800">{{ $log->action }}</span>
                                <time datetime="{{ $log->created_at->toIso8601String() }}" class="shrink-0 text-xs text-zinc-500">
                                    {{ $log->created_at->timezone($settings->timezone)->format('M j, H:i') }}
                                </time>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </div>
</x-layouts.app>
