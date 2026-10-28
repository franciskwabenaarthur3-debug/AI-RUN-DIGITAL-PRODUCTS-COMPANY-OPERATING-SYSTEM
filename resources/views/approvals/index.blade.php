<x-layouts.app title="Approvals">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Approvals</h1>
        <p class="text-sm text-zinc-600">Level 2 actions wait here for the founder. Each request shows the exact payload that will run; changing it needs a new request.</p>
    </div>

    <form method="GET" action="{{ route('approvals.index') }}" class="card mb-6 flex flex-wrap items-end gap-3">
        <div>
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

    @if ($approvals->isEmpty())
        <div class="card text-sm text-zinc-600">{{ array_filter($filters) ? 'No approval requests match this filter.' : 'No approval requests yet. They appear when an agent asks to run a Level 2 action.' }}</div>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-3">Action</th>
                        <th scope="col" class="px-4 py-3">Requested by</th>
                        <th scope="col" class="px-4 py-3">Risk</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Expires</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($approvals as $approval)
                        <tr>
                            <td class="px-4 py-3"><a href="{{ route('approvals.show', $approval) }}" class="font-mono text-xs font-medium underline-offset-2 hover:underline">{{ $approval->action_type }}</a> <span class="text-zinc-500">#{{ $approval->id }}</span></td>
                            <td class="px-4 py-3 text-zinc-700">{{ $approval->requesterName() }}</td>
                            <td class="px-4 py-3 text-zinc-700">{{ $approval->risk_level->label() }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$approval->status" /></td>
                            <td class="px-4 py-3 text-zinc-700">{{ $approval->expires_at->format('M j, Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $approvals->links() }}</div>
    @endif
</x-layouts.app>
