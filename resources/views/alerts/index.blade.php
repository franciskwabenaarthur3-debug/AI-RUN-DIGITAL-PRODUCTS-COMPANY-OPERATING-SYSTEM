<x-layouts.app title="Alerts">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Alerts</h1>
            <p class="text-sm text-zinc-600">Raised from recorded events: overdue or failed maintenance, renewals, failed releases and agent runs, approvals that need checking, and AI spend. Shown here only; nothing is emailed.</p>
        </div>
        <a href="{{ route('alerts.index', $showAll ? [] : ['all' => 1]) }}" class="btn-secondary">{{ $showAll ? 'Open alerts only' : 'Show acknowledged too' }}</a>
    </div>

    <section class="card" aria-label="Alerts">
        @if ($alerts->isEmpty())
            <p class="text-sm text-zinc-600">{{ $showAll ? 'No alerts have been raised.' : 'No open alerts.' }}</p>
        @else
            <ul class="divide-y divide-zinc-100 text-sm">
                @foreach ($alerts as $alert)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 font-medium">
                                <x-status-badge :status="$alert->severity" />
                                @if ($url = $alert->subjectUrl())<a href="{{ $url }}" class="underline-offset-2 hover:underline">{{ $alert->title }}</a>@else{{ $alert->title }}@endif
                            </p>
                            @if ($alert->detail)<p class="mt-1 whitespace-pre-line text-zinc-600">{{ $alert->detail }}</p>@endif
                            <p class="mt-1 text-xs text-zinc-500">Raised {{ $alert->created_at->format('M j, Y H:i') }}@if ($alert->acknowledged_at) · acknowledged {{ $alert->acknowledged_at->format('M j, Y H:i') }} by {{ $alert->acknowledgedBy?->name ?? 'unknown' }}@endif</p>
                        </div>
                        @if (! $alert->acknowledged_at)
                            @can('acknowledge', $alert)
                                <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}" class="shrink-0">
                                    @csrf
                                    <button type="submit" class="btn-secondary px-2 py-1 text-xs" aria-label="Acknowledge: {{ $alert->title }}">Acknowledge</button>
                                </form>
                            @endcan
                        @endif
                    </li>
                @endforeach
            </ul>
            <div class="mt-4">{{ $alerts->links() }}</div>
        @endif
    </section>
</x-layouts.app>
