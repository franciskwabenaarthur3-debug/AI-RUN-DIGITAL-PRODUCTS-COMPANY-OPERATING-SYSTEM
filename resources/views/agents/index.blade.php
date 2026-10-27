<x-layouts.app title="Agents">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Agents</h1>
        <p class="text-sm text-zinc-600">The logical agents, their limits and the tools they may use. Agents start disabled; only the founder can enable them or change permissions.</p>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                <tr>
                    <th scope="col" class="px-4 py-3">Agent</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Runtime / model</th>
                    <th scope="col" class="px-4 py-3">Instructions</th>
                    <th scope="col" class="px-4 py-3">Tools</th>
                    <th scope="col" class="px-4 py-3">Runs (failed)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach ($agents as $agent)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('agents.show', $agent) }}" class="font-medium underline-offset-2 hover:underline">{{ $agent->name }}</a>
                            <div class="text-xs text-zinc-500">{{ $agent->role->label() }}</div>
                        </td>
                        <td class="px-4 py-3"><x-status-badge :status="$agent->status" /></td>
                        <td class="px-4 py-3 text-zinc-700">{{ $agent->runtime }}{{ $agent->model_key ? ' / '.$agent->model_key : '' }}</td>
                        <td class="px-4 py-3 text-zinc-700">{{ $agent->instructions_version }}</td>
                        <td class="px-4 py-3 text-zinc-700">{{ $agent->tools_count }}</td>
                        <td class="px-4 py-3 text-zinc-700">{{ $agent->runs_count }} <span class="{{ $agent->failed_runs_count ? 'text-red-700' : 'text-zinc-500' }}">({{ $agent->failed_runs_count }})</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($agents->isEmpty())
        <p class="mt-4 text-sm text-zinc-600">No agents registered. Run <code>php artisan db:seed</code> to register the default agents.</p>
    @endif
</x-layouts.app>
