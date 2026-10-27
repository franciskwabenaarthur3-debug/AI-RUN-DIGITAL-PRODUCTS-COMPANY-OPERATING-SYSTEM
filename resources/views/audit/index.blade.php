<x-layouts.app title="Audit log">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Audit log</h1>
            <p class="text-sm text-zinc-600">Append-only record of consequential actions.</p>
        </div>
        <form method="GET" action="{{ route('audit.index') }}" class="flex items-end gap-2">
            <div>
                <label for="action" class="form-label">Action</label>
                <select id="action" name="action" class="form-input">
                    <option value="">All actions</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected($selectedAction === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary">Filter</button>
        </form>
    </div>

    @if ($logs->isEmpty())
        <div class="card text-sm text-zinc-600">No audit events match.</div>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-3">When (UTC)</th>
                        <th scope="col" class="px-4 py-3">Action</th>
                        <th scope="col" class="px-4 py-3">Actor</th>
                        <th scope="col" class="px-4 py-3">Resource</th>
                        <th scope="col" class="px-4 py-3">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($logs as $log)
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-600">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $log->action }}</td>
                            <td class="px-4 py-3">
                                {{ $log->actor_type }}{{ $log->actor_id ? ' · '.($actors[$log->actor_id] ?? '#'.$log->actor_id) : '' }}
                            </td>
                            <td class="px-4 py-3 text-zinc-600">{{ $log->resource_type ? class_basename($log->resource_type).' #'.$log->resource_id : '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($log->metadata)
                                    <details>
                                        <summary class="cursor-pointer text-emerald-700">Show</summary>
                                        <pre class="mt-2 max-w-md overflow-x-auto whitespace-pre-wrap text-xs text-zinc-700">{{ json_encode($log->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
</x-layouts.app>
