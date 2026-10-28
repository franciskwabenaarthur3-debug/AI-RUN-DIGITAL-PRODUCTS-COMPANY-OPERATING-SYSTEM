<x-layouts.app :title="'Approval #'.$approval->id">
    @php($pending = $approval->status === App\Enums\ApprovalStatus::Pending && ! $approval->isExpired())
    <div class="mb-6">
        <p class="text-sm"><a href="{{ route('approvals.index') }}" class="text-emerald-700 underline">Approvals</a></p>
        <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight"><span class="font-mono text-xl">{{ $approval->action_type }}</span> <x-status-badge :status="$approval->status" /></h1>
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    @if (! $intact)
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">The stored payload does not match its hash. It was changed after the request was made and cannot be approved or executed.</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="request">
                <h2 id="request" class="mb-4 font-semibold">Request</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-zinc-500">Requested by</dt><dd>{{ $approval->requesterName() }}</dd></div>
                    <div><dt class="text-zinc-500">Risk</dt><dd>{{ $approval->risk_level->label() }}</dd></div>
                    <div><dt class="text-zinc-500">Expires</dt><dd>{{ $approval->expires_at->format('M j, Y H:i') }}</dd></div>
                    <div><dt class="text-zinc-500">Requested</dt><dd>{{ $approval->created_at->format('M j, Y H:i') }}</dd></div>
                    @if ($approval->task)
                        <div><dt class="text-zinc-500">Task</dt><dd><a href="{{ route('tasks.show', $approval->task) }}" class="underline">#{{ $approval->task->id }} {{ $approval->task->title }}</a></dd></div>
                    @endif
                    @if ($approval->resource_type)
                        <div><dt class="text-zinc-500">Resource</dt><dd>
                            @if ($approval->resource_type === 'lead')
                                <a href="{{ route('leads.show', $approval->resource_id) }}" class="underline">Lead #{{ $approval->resource_id }}</a>
                            @elseif ($approval->resource_type === 'proposal')
                                <a href="{{ route('proposals.show', $approval->resource_id) }}" class="underline">Proposal #{{ $approval->resource_id }}</a>
                            @else
                                {{ $approval->resource_type }} #{{ $approval->resource_id }}
                            @endif
                        </dd></div>
                    @endif
                </dl>
                <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-zinc-500">Reason</h3>
                <p class="mt-1 whitespace-pre-line text-sm">{{ $approval->reason }}</p>
                <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">Rollback plan</h3>
                <p class="mt-1 whitespace-pre-line text-sm">{{ $approval->rollback_plan }}</p>
                @if ($approval->evidence)
                    <h3 class="mt-4 text-xs font-semibold uppercase tracking-wide text-zinc-500">Evidence</h3>
                    <ul class="mt-1 list-disc pl-5 text-sm">@foreach ($approval->evidence as $line)<li class="break-all">{{ $line }}</li>@endforeach</ul>
                @endif
                @if ($approval->action_type === App\Services\ProposalService::SEND_ACTION)
                    <p class="mt-4 rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-900">Approving lets the founder send exactly this version to exactly this recipient, once. Any change to the proposal needs a new version and a new approval. <a href="{{ route('proposals.preview', $approval->resource_id) }}" class="underline">Preview what the recipient will get</a>.</p>
                @endif
                @if (str_starts_with($approval->action_type, 'agent.'))
                    <p class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">Approving records your decision only. Company OS does not carry out this action; a person does.</p>
                @endif
            </section>

            <section class="card" aria-labelledby="payload">
                <h2 id="payload" class="mb-1 font-semibold">Exact payload</h2>
                <p class="mb-3 text-xs text-zinc-500">SHA-256 <span class="break-all font-mono">{{ $approval->payload_hash }}</span></p>
                <pre class="overflow-x-auto whitespace-pre-wrap rounded-md bg-zinc-50 p-3 text-xs">{{ json_encode($approval->payload_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </section>

            @if ($approval->decision || $execution || $approval->execution_result)
                <section class="card" aria-labelledby="outcome">
                    <h2 id="outcome" class="mb-4 font-semibold">Outcome</h2>
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        @if ($approval->decision)
                            <div><dt class="text-zinc-500">Decision</dt><dd>{{ ucfirst($approval->decision->decision) }} by {{ $approval->decision->approver->name }} on {{ $approval->decision->created_at->format('M j, Y H:i') }}</dd></div>
                            @if ($approval->decision->reason)
                                <div><dt class="text-zinc-500">Decision note</dt><dd class="whitespace-pre-line">{{ $approval->decision->reason }}</dd></div>
                            @endif
                        @endif
                        @if ($execution)
                            <div><dt class="text-zinc-500">Tool call</dt><dd><span class="font-mono text-xs">{{ $execution->tool_name }}</span> <x-status-badge :status="$execution->status" />@if ($execution->error_message)<span class="block text-red-800">{{ $execution->error_message }}</span>@endif</dd></div>
                        @endif
                        @if ($approval->executed_at)
                            <div><dt class="text-zinc-500">Executed</dt><dd>{{ $approval->executed_at->format('M j, Y H:i:s') }}</dd></div>
                        @endif
                    </dl>
                    @if ($approval->execution_result)
                        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded-md bg-zinc-50 p-3 text-xs">{{ json_encode($approval->execution_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                    @if ($approval->status === App\Enums\ApprovalStatus::NeedsReconciliation)
                        <p class="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">Execution started but its outcome was never recorded. Check whether the action happened before doing anything else; it will not be retried automatically.</p>
                    @endif
                </section>
            @endif
        </div>

        @if ($pending && $intact)
            @can('decide', $approval)
                <aside class="space-y-6" aria-label="Decision">
                    <section class="card">
                        <h2 class="mb-3 font-semibold">Approve</h2>
                        <form method="POST" action="{{ route('approvals.approve', $approval) }}" class="space-y-2">
                            @csrf
                            <label for="approve_reason" class="form-label">Note <span class="font-normal text-zinc-500">(optional)</span></label>
                            <input id="approve_reason" name="reason" maxlength="2000" class="form-input">
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-zinc-300 text-emerald-700"> I reviewed the exact payload above and approve it</label>
                            <button type="submit" class="btn-primary w-full">Approve</button>
                        </form>
                    </section>
                    <section class="card">
                        <h2 class="mb-3 font-semibold">Reject</h2>
                        <form method="POST" action="{{ route('approvals.reject', $approval) }}" class="space-y-2">
                            @csrf
                            <label for="reject_reason" class="form-label">Reason</label>
                            <input id="reject_reason" name="reason" required maxlength="2000" class="form-input">
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium hover:bg-zinc-50">Reject</button>
                        </form>
                    </section>
                </aside>
            @else
                <aside><p class="card text-sm text-zinc-600">Waiting for the founder's decision.</p></aside>
            @endcan
        @endif
    </div>
</x-layouts.app>
