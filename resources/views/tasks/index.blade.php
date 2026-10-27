<x-layouts.app title="Tasks">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Tasks</h1>
            <p class="text-sm text-zinc-600">Work assigned to agents, with every run, output, error and cost recorded.</p>
        </div>
        @can('create', App\Models\Task::class)
            <a href="{{ route('tasks.create') }}" class="btn-primary">New task</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('tasks.index') }}" class="card mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
        <div class="lg:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Task title" class="form-input">
        </div>
        <div>
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-input">
                <option value="">Any status</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected(($filters['status'] ?? null) === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="agent" class="form-label">Agent</label>
            <select id="agent" name="agent" class="form-input">
                <option value="">Any agent</option>
                @foreach ($agents as $a)
                    <option value="{{ $a->id }}" @selected((int) ($filters['agent'] ?? 0) === $a->id)>{{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2">
            <div class="flex-1">
                <label for="priority" class="form-label">Priority</label>
                <select id="priority" name="priority" class="form-input">
                    <option value="">Any</option>
                    @foreach ($priorities as $p)
                        <option value="{{ $p->value }}" @selected(($filters['priority'] ?? null) === $p->value)>{{ $p->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary">Filter</button>
        </div>
    </form>

    @if ($tasks->isEmpty())
        <div class="card text-sm text-zinc-600">{{ array_filter($filters) ? 'No tasks match these filters.' : 'No tasks yet. Enable an agent, then create a task for it.' }}</div>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-3">Task</th>
                        <th scope="col" class="px-4 py-3">Agent</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Priority</th>
                        <th scope="col" class="px-4 py-3">Due</th>
                        <th scope="col" class="px-4 py-3">Attempts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($tasks as $task)
                        <tr>
                            <td class="px-4 py-3"><a href="{{ route('tasks.show', $task) }}" class="font-medium underline-offset-2 hover:underline">{{ $task->title }}</a></td>
                            <td class="px-4 py-3 text-zinc-700">{{ $task->agent?->name ?? '—' }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$task->status" /></td>
                            <td class="px-4 py-3 text-zinc-700">{{ $task->priority->label() }}</td>
                            <td class="px-4 py-3 {{ $task->due_at?->isPast() && ! $task->status->isTerminal() ? 'font-medium text-amber-700' : 'text-zinc-700' }}">{{ $task->due_at?->format('M j, Y') ?? '—' }}</td>
                            <td class="px-4 py-3 text-zinc-700">{{ $task->attempts }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $tasks->links() }}</div>
    @endif
</x-layouts.app>
