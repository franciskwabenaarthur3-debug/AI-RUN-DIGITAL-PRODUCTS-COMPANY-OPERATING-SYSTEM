@php
    use App\Enums\MaintenanceTaskStatus;
    $plan = $task->plan;
    $results = ['ok' => 'OK', 'issue' => 'Issue found', 'not_applicable' => 'Not applicable', 'pending' => 'Not checked'];
    $health = $task->health_detail;
@endphp
<x-layouts.app :title="'Service report · '.$task->title">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
        <p class="text-sm"><a href="{{ route('maintenance.tasks.show', $task) }}" class="text-emerald-700 underline">Back to the visit</a></p>
        <button type="button" onclick="window.print()" class="btn-secondary">Print</button>
    </div>

    @if ($task->status !== MaintenanceTaskStatus::Completed)
        <p role="note" class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">This visit is {{ strtolower($task->status->label()) }}, so this report is not final.</p>
    @endif

    <article class="card space-y-6 text-sm">
        <header>
            <p class="text-zinc-500">{{ $company->company_name }}</p>
            <h1 class="text-2xl font-semibold tracking-tight">Maintenance service report</h1>
            <p class="text-zinc-600">{{ $plan->customer->company_name }} · {{ $plan->plan_name }}</p>
        </header>

        <dl class="grid gap-4 sm:grid-cols-3">
            <div><dt class="text-zinc-500">Visit</dt><dd>{{ $task->scheduled_at->format('M j, Y') }}</dd></div>
            <div><dt class="text-zinc-500">Site</dt><dd class="break-all">{{ $plan->siteUrl() ?? '—' }}</dd></div>
            <div><dt class="text-zinc-500">Completed</dt><dd>{{ $task->completed_at ? $task->completed_at->format('M j, Y').' by '.($task->completedBy?->name ?? 'unknown') : '—' }}</dd></div>
        </dl>

        <section aria-labelledby="r-checks">
            <h2 id="r-checks" class="mb-2 font-semibold">What was checked</h2>
            <table class="w-full text-left">
                <thead class="text-xs text-zinc-500"><tr><th class="py-1 pr-3 font-medium">Check</th><th class="py-1 pr-3 font-medium">Result</th><th class="py-1 font-medium">Note</th></tr></thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($task->checklist as $item)
                        <tr><td class="py-1.5 pr-3">{{ $item['item'] }}</td><td class="py-1.5 pr-3">{{ $results[$item['result']] ?? $item['result'] }}</td><td class="py-1.5 whitespace-pre-line text-zinc-600">{{ $item['note'] ?? '' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            @if ($health)
                <p class="mt-2 text-zinc-600">Health check {{ \Illuminate\Support\Carbon::parse($health['checked_at'])->format('M j, Y H:i') }}: {{ $health['healthy'] ? 'healthy' : 'not healthy' }}, HTTP {{ $health['http_status'] ?? 'none' }}{{ ($health['error'] ?? null) ? ' ('.$health['error'].')' : '' }}.</p>
            @endif
        </section>

        <section aria-labelledby="r-findings">
            <h2 id="r-findings" class="mb-2 font-semibold">Findings and what was done</h2>
            @forelse ($task->findings as $finding)
                <div class="mb-3 border-l-2 border-zinc-200 pl-3">
                    <p><span class="font-medium">{{ $finding->severity->label() }}:</span> {{ $finding->description }}</p>
                    @if ($finding->recommendation)<p class="text-zinc-600">Recommendation: {{ $finding->recommendation }}</p>@endif
                    <p class="text-zinc-600">Outcome: {{ $finding->status->label() }}{{ $finding->resolution_note ? ' · '.$finding->resolution_note : '' }}</p>
                </div>
            @empty
                <p class="text-zinc-600">Nothing needed attention.</p>
            @endforelse
        </section>

        @if ($task->evidence)
            <section aria-labelledby="r-evidence">
                <h2 id="r-evidence" class="mb-2 font-semibold">Work record</h2>
                <p class="whitespace-pre-line">{{ $task->evidence }}</p>
            </section>
        @endif

        <footer class="border-t border-zinc-100 pt-4 text-zinc-600">
            Next visit: {{ $plan->next_service_at?->format('M j, Y') ?? 'not scheduled' }} · plan term ends {{ $plan->term_ends_at->format('M j, Y') }}
        </footer>
    </article>
</x-layouts.app>
