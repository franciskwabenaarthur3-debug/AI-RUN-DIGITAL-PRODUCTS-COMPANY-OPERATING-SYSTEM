<x-layouts.app :title="$agent->name">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm"><a href="{{ route('agents.index') }}" class="text-emerald-700 underline">Agents</a></p>
            <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">{{ $agent->name }} <x-status-badge :status="$agent->status" /></h1>
            <p class="mt-1 max-w-2xl text-sm text-zinc-600">{{ $agent->description }}</p>
        </div>
        @can('create', App\Models\Task::class)
            @if ($agent->isEnabled())
                <a href="{{ route('tasks.create', ['agent' => $agent->id]) }}" class="btn-primary">New task for this agent</a>
            @endif
        @endcan
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="config">
                <h2 id="config" class="mb-4 font-semibold">Configuration and limits</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-zinc-500">Role</dt><dd>{{ $agent->role->label() }}</dd></div>
                    <div><dt class="text-zinc-500">Runtime</dt><dd>{{ $agent->runtime }}</dd></div>
                    <div><dt class="text-zinc-500">Model</dt><dd>{{ $agent->model_key ?? 'Not configured' }}</dd></div>
                    <div><dt class="text-zinc-500">Instructions version</dt><dd>{{ $agent->instructions_version }}</dd></div>
                    <div><dt class="text-zinc-500">Max steps per run</dt><dd>{{ $agent->max_steps }}</dd></div>
                    <div><dt class="text-zinc-500">Max tokens per run</dt><dd>{{ number_format($agent->max_tokens_per_run) }}</dd></div>
                    <div><dt class="text-zinc-500">Max tool calls per run</dt><dd>{{ $agent->max_tool_calls_per_run }}</dd></div>
                    <div><dt class="text-zinc-500">Timeout</dt><dd>{{ $agent->timeout_seconds }} s</dd></div>
                    <div><dt class="text-zinc-500">Automatic retries</dt><dd>{{ $agent->max_retries }}</dd></div>
                    <div><dt class="text-zinc-500">Results need review</dt><dd>{{ $agent->review_required ? 'Yes' : 'No' }}</dd></div>
                </dl>
                @if ($agent->runtime === 'mock')
                    <p class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">This agent uses the mock runtime, which performs no real work. A model provider is connected in the AI gateway phase.</p>
                @endif
            </section>

            <section class="card" aria-labelledby="usage">
                <h2 id="usage" class="mb-4 font-semibold">Usage this month</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-4">
                    <div><dt class="text-zinc-500">Model calls</dt><dd>{{ $usage->calls }}</dd></div>
                    <div><dt class="text-zinc-500">Tokens</dt><dd>{{ number_format($usage->tokens) }}</dd></div>
                    <div><dt class="text-zinc-500">Spent (estimated)</dt><dd>{{ $spent }}</dd></div>
                    <div><dt class="text-zinc-500">Monthly budget</dt><dd>{{ $budget }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-zinc-500">Actual provider charges replace estimates when billing data is imported.</p>
            </section>

            <section class="card" aria-labelledby="runs">
                <h2 id="runs" class="mb-4 font-semibold">Execution history</h2>
                @if ($runs->isEmpty())
                    <p class="text-sm text-zinc-600">No runs yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                <tr><th class="py-2 pr-4">Task</th><th class="py-2 pr-4">Attempt</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Error</th><th class="py-2">Started</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @foreach ($runs as $run)
                                    <tr>
                                        <td class="py-2 pr-4"><a href="{{ route('tasks.show', $run->task_id) }}" class="underline">{{ $run->task?->title ?? '#'.$run->task_id }}</a></td>
                                        <td class="py-2 pr-4">{{ $run->attempt }}</td>
                                        <td class="py-2 pr-4"><x-status-badge :status="$run->status" /></td>
                                        <td class="py-2 pr-4 font-mono text-xs text-red-700">{{ $run->error_code }}</td>
                                        <td class="py-2 text-zinc-600">{{ $run->started_at->format('M j, H:i:s') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $runs->links() }}</div>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            @can('update', $agent)
                <section class="card" aria-labelledby="status-heading">
                    <h2 id="status-heading" class="mb-3 font-semibold">{{ $agent->isEnabled() ? 'Disable agent' : 'Enable agent' }}</h2>
                    <form method="POST" action="{{ route('agents.status', $agent) }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="status" value="{{ $agent->isEnabled() ? 'disabled' : 'enabled' }}">
                        <p class="text-sm text-zinc-600">{{ $agent->isEnabled() ? 'A disabled agent cannot start new work. Runs already in progress finish.' : 'Once enabled, tasks assigned to this agent can be queued.' }}</p>
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> I confirm</label>
                        <button type="submit" class="{{ $agent->isEnabled() ? 'inline-flex w-full items-center justify-center rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800' : 'btn-primary w-full' }}">{{ $agent->isEnabled() ? 'Disable' : 'Enable' }}</button>
                    </form>
                </section>
            @endcan

            <section class="card" aria-labelledby="tools-heading">
                <h2 id="tools-heading" class="mb-1 font-semibold">Allowed tools</h2>
                <p class="mb-3 text-xs text-zinc-500">Tools run through a policy-checked gateway (next phase). Level 2 tools still need founder approval for each action.</p>
                @can('update', $agent)
                    <form method="POST" action="{{ route('agents.permissions', $agent) }}" class="space-y-2">
                        @csrf
                        @method('PUT')
                        @foreach ($tools as $name => [$description, $level])
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="tools[]" value="{{ $name }}" @checked(in_array($name, $granted, true)) class="mt-0.5 rounded border-zinc-300 text-emerald-700">
                                <span><span class="font-mono text-xs">{{ $name }}</span>@if ($level === 2) <span class="text-xs text-amber-700">(L2)</span>@endif<br><span class="text-xs text-zinc-500">{{ $description }}</span></span>
                            </label>
                        @endforeach
                        <button type="submit" class="btn-primary mt-2 w-full">Save permissions</button>
                    </form>
                @else
                    <ul class="space-y-1 text-sm">
                        @forelse ($granted as $tool)
                            <li class="font-mono text-xs">{{ $tool }}</li>
                        @empty
                            <li class="text-zinc-500">No tools granted.</li>
                        @endforelse
                    </ul>
                @endcan
            </section>
        </aside>
    </div>
</x-layouts.app>
