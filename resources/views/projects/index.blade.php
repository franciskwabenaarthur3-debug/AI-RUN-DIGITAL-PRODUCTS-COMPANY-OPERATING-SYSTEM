<x-layouts.app title="Projects">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Projects</h1>
        <p class="text-sm text-zinc-600">A project starts when a proposal is accepted. Production releases need passed checks, a verified staging release and the founder's approval.</p>
    </div>

    <form method="GET" action="{{ route('projects.index') }}" class="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="sm:w-64">
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-input">
                <option value="">Any status</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected(($filters['status'] ?? null) === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-primary">Filter</button>
    </form>

    @if ($projects->isEmpty())
        <div class="card text-sm text-zinc-600">
            @if (array_filter($filters))
                No projects match this filter. <a href="{{ route('projects.index') }}" class="font-medium text-emerald-700 underline">Clear filter</a>
            @else
                No projects yet. Recording a client's acceptance on a proposal creates its project.
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-3">Project</th>
                        <th scope="col" class="px-4 py-3">Client</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Milestones</th>
                        <th scope="col" class="px-4 py-3">Due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($projects as $p)
                        <tr>
                            <td class="px-4 py-3"><a href="{{ route('projects.show', $p) }}" class="font-medium text-zinc-900 underline-offset-2 hover:underline">{{ $p->name }}</a></td>
                            <td class="px-4 py-3 text-zinc-700">{{ $p->customerName() }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$p->status" /></td>
                            <td class="px-4 py-3 text-zinc-700">
                                {{ $p->completed_milestones_count }} of {{ $p->milestones_count }} done
                                @if ($p->overdue_milestones_count && $p->status->isActive())<span class="block text-xs font-medium text-red-700">{{ $p->overdue_milestones_count }} overdue</span>@endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-700">{{ $p->due_date?->format('M j, Y') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $projects->links() }}</div>
    @endif
</x-layouts.app>
