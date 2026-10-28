<x-layouts.app :title="$task->title">
    @php($S = App\Enums\TaskStatus::class)
    <div class="mb-6">
        <p class="text-sm"><a href="{{ route('tasks.index') }}" class="text-emerald-700 underline">Tasks</a></p>
        <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">{{ $task->title }} <x-status-badge :status="$task->status" /></h1>
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="details">
                <h2 id="details" class="mb-4 font-semibold">Details</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-zinc-500">Agent</dt><dd>@if ($task->agent)<a href="{{ route('agents.show', $task->agent) }}" class="underline">{{ $task->agent->name }}</a>@else — @endif</dd></div>
                    <div><dt class="text-zinc-500">Priority</dt><dd>{{ $task->priority->label() }}</dd></div>
                    <div><dt class="text-zinc-500">Due</dt><dd>{{ $task->due_at?->format('M j, Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Created by</dt><dd>{{ $task->creatorName() }}</dd></div>
                    <div><dt class="text-zinc-500">Attempts</dt><dd>{{ $task->attempts }}</dd></div>
                    <div><dt class="text-zinc-500">Cost (estimated)</dt><dd>{{ $cost }} · {{ number_format($tokens) }} tokens</dd></div>
                    @if ($task->parent)
                        <div><dt class="text-zinc-500">Parent</dt><dd><a href="{{ route('tasks.show', $task->parent) }}" class="underline">#{{ $task->parent->id }} {{ $task->parent->title }}</a></dd></div>
                    @endif
                </dl>
                @if ($task->description)
                    <p class="mt-4 whitespace-pre-line text-sm">{{ $task->description }}</p>
                @endif
                @if ($task->failure_reason)
                    <p class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{{ $task->failure_reason }}</p>
                @endif
            </section>

            @if ($task->dependencies->isNotEmpty() || $task->dependents->isNotEmpty() || $task->children->isNotEmpty())
                <section class="card" aria-labelledby="deps">
                    <h2 id="deps" class="mb-3 font-semibold">Related tasks</h2>
                    <div class="grid gap-4 text-sm sm:grid-cols-3">
                        @foreach (['Waits for' => $task->dependencies, 'Blocks' => $task->dependents, 'Subtasks' => $task->children] as $label => $list)
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</h3>
                                <ul class="mt-1 space-y-1">
                                    @forelse ($list as $t)
                                        <li><a href="{{ route('tasks.show', $t) }}" class="underline">#{{ $t->id }} {{ $t->title }}</a> <x-status-badge :status="$t->status" /></li>
                                    @empty
                                        <li class="text-zinc-500">None</li>
                                    @endforelse
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($task->result)
                <section class="card" aria-labelledby="result">
                    <h2 id="result" class="mb-1 font-semibold">Result <span class="text-sm font-normal text-zinc-500">({{ $task->result['kind'] }})</span></h2>
                    <p class="mb-3 text-xs text-zinc-500">Agent output is a {{ $task->result['kind'] }}, not proof that anything happened outside this system.</p>
                    <p class="whitespace-pre-line text-sm">{{ $task->result['summary'] }}</p>
                    @foreach (['items' => 'Details', 'recommendations' => 'Recommendations', 'assumptions' => 'Unverified assumptions'] as $key => $label)
                        @if (! empty($task->result[$key]))
                            <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</h3>
                            <ul class="mt-1 list-disc pl-5 text-sm">@foreach ($task->result[$key] as $line)<li>{{ $line }}</li>@endforeach</ul>
                        @endif
                    @endforeach
                    @if (! empty($task->result['sources']))
                        <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">Sources</h3>
                        <ul class="mt-1 list-disc pl-5 text-sm">@foreach ($task->result['sources'] as $src)<li class="break-all">{{ $src['reference'] }} <span class="text-zinc-500">(retrieved {{ $src['retrieved_at'] }})</span></li>@endforeach</ul>
                    @endif
                </section>
            @endif

            @if ($toolCalls->isNotEmpty())
                <section class="card" aria-labelledby="tool-calls">
                    <h2 id="tool-calls" class="mb-4 font-semibold">Tool calls</h2>
                    <ol class="space-y-3 text-sm">
                        @foreach ($toolCalls as $call)
                            <li class="rounded-md border border-zinc-200 p-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-xs font-medium">{{ $call->tool_name }}</span>
                                    <x-status-badge :status="$call->status" />
                                    <span class="text-xs text-zinc-500">{{ $call->started_at?->format('M j, H:i:s') }}</span>
                                    @if ($call->approval_request_id)
                                        <a href="{{ route('approvals.show', $call->approval_request_id) }}" class="text-xs text-emerald-700 underline">Approval #{{ $call->approval_request_id }}</a>
                                    @endif
                                </div>
                                @if ($call->error_code)
                                    <p class="mt-1 text-sm text-red-800"><span class="font-mono text-xs">{{ $call->error_code }}</span> {{ $call->error_message }}</p>
                                @endif
                                <details class="mt-2"><summary class="cursor-pointer text-emerald-700">Input{{ $call->result_summary ? ' and result' : '' }}</summary><pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode(['input' => $call->input_summary, 'result' => $call->result_summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            <section class="card" aria-labelledby="runs">
                <h2 id="runs" class="mb-4 font-semibold">Execution history</h2>
                @if ($runs->isEmpty())
                    <p class="text-sm text-zinc-600">No runs yet.</p>
                @else
                    <ol class="space-y-4 text-sm">
                        @foreach ($runs as $run)
                            <li class="rounded-md border border-zinc-200 p-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">Attempt {{ $run->attempt }}</span>
                                    <x-status-badge :status="$run->status" />
                                    <span class="text-xs text-zinc-500">{{ $run->runtime }}{{ $run->model_key ? ' / '.$run->model_key : '' }} · instructions {{ $run->instructions_version }}</span>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500">
                                    Started {{ $run->started_at->format('M j, H:i:s') }}{{ $run->finished_at ? ' · finished '.$run->finished_at->format('H:i:s') : '' }} ·
                                    {{ $run->usage->sum(fn ($u) => $u->input_tokens + $u->output_tokens) }} tokens · correlation <span class="font-mono">{{ $run->correlation_id }}</span>
                                </p>
                                @if ($run->error_code)
                                    <p class="mt-2 text-sm text-red-800"><span class="font-mono text-xs">{{ $run->error_code }}</span> {{ $run->error_message }}</p>
                                @endif
                                @if ($run->output)
                                    <details class="mt-2"><summary class="cursor-pointer text-emerald-700">Raw output</summary><pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode($run->output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        @can('operate', $task)
            <aside class="space-y-6" aria-label="Actions">
                @if ($task->status === $S::Created)
                    <section class="card">
                        <h2 class="mb-3 font-semibold">Start</h2>
                        <form method="POST" action="{{ route('tasks.queue', $task) }}">@csrf<button type="submit" class="btn-primary w-full">Queue task</button></form>
                    </section>
                @endif

                @if ($task->status === $S::AwaitingReview)
                    <section class="card space-y-3">
                        <h2 class="font-semibold">Review result</h2>
                        <form method="POST" action="{{ route('tasks.accept', $task) }}">@csrf<button type="submit" class="btn-primary w-full">Accept result</button></form>
                        <form method="POST" action="{{ route('tasks.reject', $task) }}" class="space-y-2">
                            @csrf
                            <label for="reject_reason" class="form-label">Reason for rejecting</label>
                            <input id="reject_reason" name="reason" required maxlength="500" class="form-input">
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium hover:bg-zinc-50">Reject</button>
                        </form>
                    </section>
                @endif

                @if (in_array($task->status, [$S::Failed, $S::Blocked], true))
                    <section class="card">
                        <h2 class="mb-3 font-semibold">Retry</h2>
                        <p class="mb-3 text-sm text-zinc-600">Queues a new attempt with a fresh retry allowance.</p>
                        <form method="POST" action="{{ route('tasks.retry', $task) }}">@csrf<button type="submit" class="btn-primary w-full">Retry task</button></form>
                    </section>
                @endif

                @if ($task->status->canTransitionTo($S::Cancelled))
                    <section class="card">
                        <h2 class="mb-3 font-semibold">Cancel</h2>
                        <form method="POST" action="{{ route('tasks.cancel', $task) }}" class="space-y-2">
                            @csrf
                            <label for="cancel_reason" class="form-label">Reason <span class="font-normal text-zinc-500">(optional)</span></label>
                            <input id="cancel_reason" name="reason" maxlength="500" class="form-input">
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> I confirm</label>
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Cancel task</button>
                        </form>
                    </section>
                @endif
            </aside>
        @endcan
    </div>
</x-layouts.app>
