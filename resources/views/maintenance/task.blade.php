@php
    use App\Enums\ApprovalStatus;
    use App\Enums\FindingSeverity;
    use App\Enums\FindingStatus;
    use App\Enums\MaintenanceTaskStatus;
    $plan = $task->plan;
    $working = $task->status === MaintenanceTaskStatus::InProgress;
    $results = ['ok' => 'OK', 'issue' => 'Issue', 'not_applicable' => 'Not applicable', 'pending' => 'Not checked yet'];
    $resultTone = ['ok' => 'text-emerald-700', 'issue' => 'text-red-700', 'not_applicable' => 'text-zinc-500', 'pending' => 'text-amber-700'];
    $health = $task->health_detail;
@endphp
<x-layouts.app :title="$task->title">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm"><a href="{{ route('maintenance.plans.show', $plan) }}" class="text-emerald-700 underline">{{ $plan->plan_name }}</a></p>
            <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">{{ $task->title }} <x-status-badge :status="$task->status" /></h1>
            <p class="text-sm {{ $task->isOverdue() ? 'font-medium text-red-700' : 'text-zinc-600' }}">{{ $plan->customer->company_name }} · due {{ $task->scheduled_at->format('M j, Y') }}{{ $task->isOverdue() ? ' (overdue)' : '' }} · {{ $task->sourceLabel() }}</p>
        </div>
        @if ($task->status === MaintenanceTaskStatus::Completed)
            <a href="{{ route('maintenance.tasks.report', $task) }}" class="btn-secondary">Service report</a>
        @endif
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif
    @if ($working)
        <p role="note" class="mb-6 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">Checks here must not change the site. Anything that changes the live site, or costs the client extra, needs the founder's approval first: record it as a risky finding and request approval.</p>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="checklist">
                <h2 id="checklist" class="mb-3 font-semibold">Checklist</h2>
                <ol class="divide-y divide-zinc-100 text-sm">
                    @foreach ($task->checklist as $i => $item)
                        <li class="py-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium">{{ $i + 1 }}. {{ $item['item'] }}</span>
                                <span class="text-xs font-medium {{ $resultTone[$item['result']] ?? 'text-zinc-600' }}">{{ $results[$item['result']] ?? $item['result'] }}</span>
                            </div>
                            @if ($item['note'])<p class="mt-1 whitespace-pre-line text-zinc-600">{{ $item['note'] }}</p>@endif
                            @if ($working)
                                @can('operate', $plan)
                                    <form method="POST" action="{{ route('maintenance.tasks.checklist', $task) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                        @csrf
                                        <input type="hidden" name="item" value="{{ $i }}">
                                        <div><label for="result_{{ $i }}" class="sr-only">Result for {{ $item['item'] }}</label>
                                            <select id="result_{{ $i }}" name="result" class="form-input py-1 text-xs">@foreach ($results as $value => $label)<option value="{{ $value }}" @selected($item['result'] === $value)>{{ $label }}</option>@endforeach</select></div>
                                        <div class="min-w-0 flex-1"><label for="note_{{ $i }}" class="sr-only">Note for {{ $item['item'] }}</label>
                                            <input id="note_{{ $i }}" name="note" value="{{ $item['note'] }}" maxlength="1000" placeholder="Note (required for an issue)" class="form-input py-1 text-xs"></div>
                                        <button type="submit" class="btn-secondary px-2 py-1 text-xs">Save</button>
                                    </form>
                                @endcan
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>

            <section class="card" aria-labelledby="findings">
                <h2 id="findings" class="mb-3 font-semibold">Findings</h2>
                @if ($task->findings->isEmpty())
                    <p class="text-sm text-zinc-600">No findings recorded.</p>
                @else
                    <ul class="space-y-4 text-sm">
                        @foreach ($task->findings as $finding)
                            @php
                                $approval = $finding->approvalRequest;
                                $approvalUsable = $approval && in_array($approval->status, [ApprovalStatus::Pending, ApprovalStatus::Approved, ApprovalStatus::Executing, ApprovalStatus::Executed], true) && ! $approval->isExpired();
                                $canAskAgain = in_array($finding->status, [FindingStatus::ApprovalRequested, FindingStatus::Approved], true) && ! $approvalUsable;
                            @endphp
                            <li class="rounded-md border border-zinc-200 p-3" id="finding-{{ $finding->id }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-status-badge :status="$finding->severity" />
                                    <x-status-badge :status="$finding->status" />
                                    @if ($finding->risky)<span class="text-xs font-medium text-amber-800">Changes the live site</span>@endif
                                </div>
                                <p class="mt-2 whitespace-pre-line">{{ $finding->description }}</p>
                                @if ($finding->recommendation)<p class="mt-1 text-zinc-600"><span class="font-medium">Recommendation:</span> {{ $finding->recommendation }}</p>@endif
                                @if ($finding->change_plan)
                                    <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                        <div><dt class="text-xs text-zinc-500">Change plan</dt><dd class="whitespace-pre-line">{{ $finding->change_plan }}</dd></div>
                                        <div><dt class="text-xs text-zinc-500">Rollback plan</dt><dd class="whitespace-pre-line">{{ $finding->rollback_plan }}</dd></div>
                                    </dl>
                                @endif
                                @if ($approval)
                                    <p class="mt-2 text-xs text-zinc-600">Founder approval: <a href="{{ route('approvals.show', $approval) }}" class="text-emerald-700 underline">#{{ $approval->id }}</a> <x-status-badge :status="$approval->status" />@if ($approval->isExpired() && $approval->status === ApprovalStatus::Pending) (expired)@endif</p>
                                @endif
                                @if ($finding->resolution_note)<p class="mt-2 text-xs text-zinc-600">{{ $finding->status->label() }} {{ $finding->resolved_at?->format('M j, Y') }}: {{ $finding->resolution_note }}</p>@endif
                                <p class="mt-1 text-xs text-zinc-500">Recorded by {{ $finding->recorder?->name ?? 'unknown' }} · {{ $finding->created_at->format('M j, Y H:i') }}</p>

                                @if ($working)
                                    @can('operate', $plan)
                                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                            @if ($finding->status === FindingStatus::Open)
                                                <form method="POST" action="{{ route('maintenance.tasks.findings.resolve', [$task, $finding]) }}" class="space-y-2">
                                                    @csrf
                                                    <p class="text-xs font-medium">Close without a live change</p>
                                                    <label for="resolve_status_{{ $finding->id }}" class="sr-only">Outcome</label>
                                                    <select id="resolve_status_{{ $finding->id }}" name="status" class="form-input py-1 text-xs">
                                                        @unless ($finding->risky)<option value="{{ FindingStatus::Resolved->value }}">Resolved</option>@endunless
                                                        <option value="{{ FindingStatus::WontFix->value }}">Won't fix</option>
                                                    </select>
                                                    <label for="resolve_note_{{ $finding->id }}" class="sr-only">Note</label>
                                                    <textarea id="resolve_note_{{ $finding->id }}" name="note" rows="2" required maxlength="2000" placeholder="What was done, or why it will not be fixed" class="form-input text-xs"></textarea>
                                                    <button type="submit" class="btn-secondary px-2 py-1 text-xs">Close finding</button>
                                                </form>
                                            @endif
                                            @if ($finding->status === FindingStatus::Open || $canAskAgain)
                                                <form method="POST" action="{{ route('maintenance.tasks.findings.request-change', [$task, $finding]) }}" class="space-y-2">
                                                    @csrf
                                                    <p class="text-xs font-medium">{{ $canAskAgain ? 'Request approval again' : 'Request approval for a live change' }}</p>
                                                    <label for="change_plan_{{ $finding->id }}" class="sr-only">Change plan</label>
                                                    <textarea id="change_plan_{{ $finding->id }}" name="change_plan" rows="2" required maxlength="3000" placeholder="Exactly what will change on the live site" class="form-input text-xs">{{ $finding->change_plan }}</textarea>
                                                    <label for="rollback_plan_{{ $finding->id }}" class="sr-only">Rollback plan</label>
                                                    <textarea id="rollback_plan_{{ $finding->id }}" name="rollback_plan" rows="2" required maxlength="3000" placeholder="How to undo it, and the backup to restore" class="form-input text-xs">{{ $finding->rollback_plan }}</textarea>
                                                    <button type="submit" class="btn-secondary px-2 py-1 text-xs">Send to the founder</button>
                                                </form>
                                            @endif
                                            @if ($finding->status === FindingStatus::Approved && $approvalUsable)
                                                <form method="POST" action="{{ route('maintenance.tasks.findings.record-change', [$task, $finding]) }}" class="space-y-2 sm:col-span-2">
                                                    @csrf
                                                    <p class="text-xs font-medium">Record the approved change</p>
                                                    <label for="change_note_{{ $finding->id }}" class="sr-only">What was done</label>
                                                    <textarea id="change_note_{{ $finding->id }}" name="note" rows="2" required maxlength="2000" placeholder="What you changed, when, and how you checked it" class="form-input text-xs"></textarea>
                                                    <label class="flex items-start gap-2 text-xs"><input type="checkbox" name="confirm" value="1" required class="mt-0.5"> I made exactly the approved change and verified the site afterwards.</label>
                                                    <button type="submit" class="btn-primary px-2 py-1 text-xs">Record change</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endcan
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($working)
                    @can('operate', $plan)
                        <form method="POST" action="{{ route('maintenance.tasks.findings.store', $task) }}" class="mt-4 space-y-3 border-t border-zinc-100 pt-4">
                            @csrf
                            <h3 class="text-sm font-semibold">Record a finding</h3>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div><label for="severity" class="form-label">Severity</label><select id="severity" name="severity" class="form-input">@foreach (FindingSeverity::cases() as $s)<option value="{{ $s->value }}" @selected(old('severity') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
                                <div class="sm:col-span-2"><label for="description" class="form-label">What was found</label><textarea id="description" name="description" rows="2" required maxlength="2000" class="form-input">{{ old('description') }}</textarea></div>
                            </div>
                            <div><label for="recommendation" class="form-label">Recommendation <span class="font-normal text-zinc-500">(optional)</span></label><textarea id="recommendation" name="recommendation" rows="2" maxlength="2000" class="form-input">{{ old('recommendation') }}</textarea></div>
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="risky" value="1" @checked(old('risky')) class="mt-1"> Fixing it changes the live site or costs the client extra (needs the founder's approval)</label>
                            <button type="submit" class="btn-secondary">Record finding</button>
                        </form>
                    @endcan
                @endif
            </section>

            @if ($task->evidence || $task->notes)
                <section class="card" aria-labelledby="record">
                    <h2 id="record" class="mb-3 font-semibold">Record</h2>
                    <dl class="space-y-3 text-sm">
                        @if ($task->evidence)<div><dt class="text-zinc-500">Completion evidence</dt><dd class="whitespace-pre-line">{{ $task->evidence }}</dd></div>@endif
                        @if ($task->notes)<div><dt class="text-zinc-500">Notes</dt><dd class="whitespace-pre-line">{{ $task->notes }}</dd></div>@endif
                        @if ($task->completed_at)<div><dt class="text-zinc-500">Closed</dt><dd>{{ $task->completed_at->format('M j, Y H:i') }} by {{ $task->completedBy?->name ?? 'unknown' }}</dd></div>@endif
                    </dl>
                </section>
            @endif
        </div>

        <aside class="space-y-6" aria-label="Visit actions">
            <section class="card">
                <h2 class="mb-2 font-semibold">Site health</h2>
                @if ($health)
                    <p class="text-sm {{ $health['healthy'] ? 'text-emerald-700' : 'text-red-700' }}">{{ $health['healthy'] ? 'Healthy' : 'Not healthy' }}: HTTP {{ $health['http_status'] ?? 'none' }} in {{ $health['duration_ms'] ?? '?' }} ms</p>
                    @if ($health['error'] ?? null)<p class="text-xs text-zinc-600">{{ $health['error'] }}</p>@endif
                    <p class="text-xs text-zinc-500">{{ $health['url'] }} · {{ \Illuminate\Support\Carbon::parse($health['checked_at'])->format('M j, Y H:i') }}</p>
                @elseif (! $plan->siteUrl())
                    <p class="text-sm text-zinc-600">The plan's project has no production URL, so there is nothing to check.</p>
                @else
                    <p class="text-sm text-zinc-600">Not checked on this visit.</p>
                @endif
                @if ($working && $plan->siteUrl())
                    @can('operate', $plan)
                        <form method="POST" action="{{ route('maintenance.tasks.health-check', $task) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="btn-secondary w-full">Run health check</button>
                            <p class="mt-1 text-xs text-zinc-500">One GET request to {{ $plan->siteUrl() }}. It changes nothing.</p>
                        </form>
                    @endcan
                @endif
            </section>

            @can('operate', $plan)
                @if ($task->status === MaintenanceTaskStatus::Scheduled)
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Start the visit</h2>
                        <form method="POST" action="{{ route('maintenance.tasks.start', $task) }}">
                            @csrf
                            <button type="submit" class="btn-primary w-full">Start</button>
                        </form>
                    </section>
                @endif
                @if ($working)
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Complete</h2>
                        <form method="POST" action="{{ route('maintenance.tasks.complete', $task) }}" class="space-y-3">
                            @csrf
                            <div><label for="evidence" class="form-label">Evidence</label><textarea id="evidence" name="evidence" rows="4" required maxlength="5000" placeholder="What was checked and done, and where any reports, logs or backups are" class="form-input">{{ old('evidence') }}</textarea></div>
                            <button type="submit" class="btn-primary w-full">Complete visit</button>
                            <p class="text-xs text-zinc-500">Every checklist item needs a result and every finding must be closed. The record cannot change afterwards.</p>
                        </form>
                    </section>
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Could not be done</h2>
                        <form method="POST" action="{{ route('maintenance.tasks.fail', $task) }}" class="space-y-3">
                            @csrf
                            <div><label for="fail_reason" class="form-label">Why</label><textarea id="fail_reason" name="reason" rows="2" required maxlength="2000" class="form-input"></textarea></div>
                            <button type="submit" class="btn-secondary w-full">Mark failed</button>
                            <p class="text-xs text-zinc-500">Raises a critical alert.</p>
                        </form>
                    </section>
                @endif
                @if ($task->status->isOpen())
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Cancel the visit</h2>
                        <form method="POST" action="{{ route('maintenance.tasks.cancel', $task) }}" class="space-y-3">
                            @csrf
                            <div><label for="cancel_reason" class="form-label">Reason</label><input id="cancel_reason" name="reason" required maxlength="2000" class="form-input"></div>
                            <button type="submit" class="btn-secondary w-full">Cancel visit</button>
                            <p class="text-xs text-zinc-500">Any approval still open for this visit is withdrawn.</p>
                        </form>
                    </section>
                @endif
            @endcan
        </aside>
    </div>
</x-layouts.app>
