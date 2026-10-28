@php
    use App\Enums\CheckStatus;
    use App\Enums\DeliverableStatus;
    use App\Enums\MilestoneStatus;
    use App\Enums\ProjectStatus;
    use App\Enums\ReleaseStatus;
    use App\Enums\TaskStatus;
    use App\Services\ProjectService;
    $money = fn (int $minor) => App\Support\Money::format($minor, $project->currency);
    $allTasks = $project->milestones->flatMap->tasks->concat($unplannedTasks);
    $overdue = $project->milestones->filter->isOverdue();
    $blockedTasks = $allTasks->where('status', TaskStatus::Blocked);
    $failedChecks = $project->releases->whereIn('status', [ReleaseStatus::Draft, ReleaseStatus::Deployed, ReleaseStatus::Verified])->flatMap(fn ($r) => $r->checks->where('status', CheckStatus::Failed)->map(fn ($c) => [$r, $c]));
    $failedReleases = $project->releases->where('status', ReleaseStatus::FailedVerification);
    $staging = $project->releases->firstWhere('environment', App\Enums\ReleaseEnvironment::Staging);
    $taskLine = function ($task) {
        $who = $task->agent?->name ?? $task->assignee?->name ?? 'Unassigned';
        return $who.($task->due_at ? ' · due '.$task->due_at->format('M j') : '');
    };
@endphp
<x-layouts.app :title="$project->name">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm"><a href="{{ route('projects.index') }}" class="text-emerald-700 underline">Projects</a></p>
            <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">{{ $project->name }} <x-status-badge :status="$project->status" /></h1>
            <p class="text-sm text-zinc-600">For {{ $project->customerName() }} · {{ ProjectService::dueLabel($project->due_date) }} · budget {{ $money($project->budget_minor) }}</p>
        </div>
        @can('update', $project)
            <a href="{{ route('projects.edit', $project) }}" class="btn-secondary">Edit details</a>
        @endcan
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="overview">
                <h2 id="overview" class="mb-4 font-semibold">Overview</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">Client</dt><dd>
                        @if ($project->customer)<a href="{{ route('customers.show', $project->customer) }}" class="text-emerald-700 underline">{{ $project->customer->company_name }}</a>@else{{ $project->customerName() }}@endif
                    </dd></div>
                    <div><dt class="text-zinc-500">From proposal</dt><dd>
                        @if ($project->proposal)<a href="{{ route('proposals.show', $project->proposal) }}" class="text-emerald-700 underline">{{ $project->proposal->label() }}</a>@else—@endif
                    </dd></div>
                    <div><dt class="text-zinc-500">Dates</dt><dd>{{ $project->start_date?->format('M j, Y') ?? '?' }} to {{ $project->due_date?->format('M j, Y') ?? '?' }}</dd></div>
                    <div><dt class="text-zinc-500">Budget</dt><dd>{{ $money($project->budget_minor) }}</dd></div>
                    @foreach (['repository_url' => 'Repository', 'staging_url' => 'Staging', 'production_url' => 'Production'] as $field => $label)
                        <div><dt class="text-zinc-500">{{ $label }}</dt><dd class="break-all">
                            @if ($project->{$field})<a href="{{ $project->{$field} }}" class="text-emerald-700 underline" rel="noopener noreferrer" target="_blank">{{ $project->{$field} }}</a>@else<span class="text-zinc-500">Not set</span>@endif
                        </dd></div>
                    @endforeach
                    @if ($staging)
                        <div><dt class="text-zinc-500">Latest staging release</dt><dd><a href="{{ route('releases.show', $staging) }}" class="text-emerald-700 underline">{{ $staging->version }}</a> <x-status-badge :status="$staging->status" /></dd></div>
                    @endif
                    @if ($project->description)
                        <div class="sm:col-span-2"><dt class="text-zinc-500">Description</dt><dd class="whitespace-pre-line">{{ $project->description }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="card" aria-labelledby="milestones-heading" id="milestones">
                <h2 class="mb-4 font-semibold" id="milestones-heading">Milestones and tasks</h2>
                <div class="space-y-5">
                    @foreach ($project->milestones as $milestone)
                        <div class="rounded-md border border-zinc-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <h3 class="flex flex-wrap items-center gap-2 font-medium">{{ $milestone->name }} <x-status-badge :status="$milestone->status" /></h3>
                                    <p class="text-xs {{ $milestone->isOverdue() ? 'font-medium text-red-700' : 'text-zinc-500' }}">{{ $milestone->status === MilestoneStatus::Completed ? 'Completed '.$milestone->completed_at?->format('M j, Y') : ProjectService::dueLabel($milestone->due_at) }}</p>
                                </div>
                                @can('update', $project)
                                    <form method="POST" action="{{ route('projects.milestones.status', [$project, $milestone]) }}" class="flex items-center gap-2">
                                        @csrf
                                        <label for="ms-{{ $milestone->id }}" class="sr-only">Status of {{ $milestone->name }}</label>
                                        <select id="ms-{{ $milestone->id }}" name="status" class="form-input py-1">
                                            @foreach (MilestoneStatus::cases() as $s)<option value="{{ $s->value }}" @selected($milestone->status === $s)>{{ $s->label() }}</option>@endforeach
                                        </select>
                                        <button type="submit" class="btn-secondary py-1">Set</button>
                                    </form>
                                @endcan
                            </div>
                            @if ($milestone->tasks->isNotEmpty())
                                <ul class="mt-3 divide-y divide-zinc-100 text-sm">
                                    @foreach ($milestone->tasks as $task)
                                        @include('projects._task', ['task' => $task])
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach

                    @if ($unplannedTasks->isNotEmpty())
                        <div class="rounded-md border border-zinc-200 p-4">
                            <h3 class="font-medium">Not in a milestone</h3>
                            <ul class="mt-3 divide-y divide-zinc-100 text-sm">
                                @foreach ($unplannedTasks as $task)
                                    @include('projects._task', ['task' => $task])
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                @can('update', $project)
                    <div class="mt-5 grid gap-5 border-t border-zinc-100 pt-5 sm:grid-cols-2">
                        <form method="POST" action="{{ route('projects.tasks.store', $project) }}" class="space-y-3">
                            @csrf
                            <h3 class="font-medium">Add a task</h3>
                            <div>
                                <label for="task_title" class="form-label">Title</label>
                                <input id="task_title" name="title" required maxlength="255" class="form-input">
                                <x-input-error for="title" />
                            </div>
                            <div>
                                <label for="task_milestone" class="form-label">Milestone</label>
                                <select id="task_milestone" name="milestone_id" class="form-input">
                                    <option value="">None</option>
                                    @foreach ($project->milestones as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="task_assignee" class="form-label">Who does it</label>
                                <select id="task_assignee" name="assignee" class="form-input">
                                    <option value="">Unassigned</option>
                                    <optgroup label="People">@foreach ($people as $u)<option value="user:{{ $u->id }}">{{ $u->name }}</option>@endforeach</optgroup>
                                    <optgroup label="Agents">@foreach ($agents as $a)<option value="agent:{{ $a->id }}">{{ $a->name }}</option>@endforeach</optgroup>
                                </select>
                                <x-input-error for="assignee" />
                                <p class="mt-1 text-xs text-zinc-500">Agent tasks run and are reviewed from the Tasks page.</p>
                            </div>
                            <div>
                                <label for="task_due" class="form-label">Due</label>
                                <input id="task_due" name="due_at" type="date" class="form-input">
                            </div>
                            <button type="submit" class="btn-primary">Add task</button>
                        </form>
                        <form method="POST" action="{{ route('projects.milestones.store', $project) }}" class="space-y-3">
                            @csrf
                            <h3 class="font-medium">Add a milestone</h3>
                            <div>
                                <label for="milestone_name" class="form-label">Name</label>
                                <input id="milestone_name" name="name" required maxlength="200" class="form-input">
                            </div>
                            <div>
                                <label for="milestone_due" class="form-label">Due</label>
                                <input id="milestone_due" name="due_at" type="date" class="form-input">
                            </div>
                            <button type="submit" class="btn-secondary">Add milestone</button>
                        </form>
                    </div>
                @endcan
            </section>

            <section class="card" aria-labelledby="deliverables-heading" id="deliverables">
                <h2 id="deliverables-heading" class="mb-4 font-semibold">Deliverables</h2>
                @if ($project->deliverables->isEmpty())
                    <p class="text-sm text-zinc-600">No deliverables recorded yet.</p>
                @else
                    <ul class="divide-y divide-zinc-100 text-sm">
                        @foreach ($project->deliverables as $d)
                            <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="font-medium">{{ $d->name }} <span class="font-normal text-zinc-500">· {{ str_replace('_', ' ', $d->type) }} · v{{ $d->version }}</span></p>
                                    @if ($d->url)<a href="{{ $d->url }}" class="break-all text-xs text-emerald-700 underline" rel="noopener noreferrer" target="_blank">{{ $d->url }}</a>@endif
                                </div>
                                @can('update', $project)
                                    <form method="POST" action="{{ route('projects.deliverables.update', [$project, $d]) }}" class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        @method('PUT')
                                        <label for="d-{{ $d->id }}-status" class="sr-only">Status of {{ $d->name }}</label>
                                        <select id="d-{{ $d->id }}-status" name="status" class="form-input w-auto py-1">
                                            @foreach (DeliverableStatus::cases() as $s)<option value="{{ $s->value }}" @selected($d->status === $s)>{{ $s->label() }}</option>@endforeach
                                        </select>
                                        <label for="d-{{ $d->id }}-url" class="sr-only">New link for {{ $d->name }}</label>
                                        <input id="d-{{ $d->id }}-url" name="url" type="url" placeholder="New link (new version)" maxlength="255" class="form-input w-48 py-1">
                                        <button type="submit" class="btn-secondary py-1">Save</button>
                                    </form>
                                @else
                                    <x-status-badge :status="$d->status" />
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @endif
                @can('update', $project)
                    <form method="POST" action="{{ route('projects.deliverables.store', $project) }}" class="mt-4 grid gap-3 border-t border-zinc-100 pt-4 sm:grid-cols-2">
                        @csrf
                        <div>
                            <label for="deliverable_name" class="form-label">Name</label>
                            <input id="deliverable_name" name="name" required maxlength="200" class="form-input">
                        </div>
                        <div>
                            <label for="deliverable_type" class="form-label">Type</label>
                            <select id="deliverable_type" name="type" class="form-input">
                                @foreach (App\Models\ProjectDeliverable::TYPES as $t)<option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="deliverable_url" class="form-label">Link <span class="font-normal text-zinc-500">(optional)</span></label>
                            <input id="deliverable_url" name="url" type="url" maxlength="255" placeholder="https://" class="form-input">
                            <x-input-error for="url" />
                        </div>
                        <div>
                            <label for="deliverable_milestone" class="form-label">Milestone</label>
                            <select id="deliverable_milestone" name="milestone_id" class="form-input">
                                <option value="">None</option>
                                @foreach ($project->milestones as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2"><button type="submit" class="btn-secondary">Add deliverable</button></div>
                    </form>
                @endcan
            </section>

            <section class="card" aria-labelledby="releases-heading" id="releases">
                <h2 id="releases-heading" class="mb-1 font-semibold">Releases and quality checks</h2>
                <p class="mb-4 text-sm text-zinc-600">Deploy with your own tools and record it here. Production needs every mandatory check passed, the same version verified on staging, the client's approval and the founder's approval.</p>
                @if ($project->releases->isEmpty())
                    <p class="text-sm text-zinc-600">No releases yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                <tr><th scope="col" class="py-2 pr-3">Release</th><th scope="col" class="py-2 pr-3">Status</th><th scope="col" class="py-2 pr-3">Checks</th><th scope="col" class="py-2">Health</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100">
                                @foreach ($project->releases as $r)
                                    @php($mandatory = $r->checks->where('mandatory', true))
                                    <tr>
                                        <td class="py-2 pr-3"><a href="{{ route('releases.show', $r) }}" class="font-medium text-emerald-700 underline">{{ $r->label() }}</a><span class="block text-xs text-zinc-500">{{ $r->created_at->format('M j, Y') }}</span></td>
                                        <td class="py-2 pr-3"><x-status-badge :status="$r->status" /></td>
                                        <td class="py-2 pr-3">
                                            {{ $mandatory->where('status', CheckStatus::Passed)->count() }} of {{ $mandatory->count() }} mandatory passed
                                            @if ($r->checks->contains('status', CheckStatus::Failed))<span class="block text-xs font-medium text-red-700">{{ $r->checks->where('status', CheckStatus::Failed)->count() }} failed</span>@endif
                                        </td>
                                        <td class="py-2">{{ $r->health_status ? ucfirst($r->health_status).' · '.$r->health_checked_at?->format('M j H:i') : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @can('update', $project)
                    @if ($project->status->isActive())
                        <details class="mt-4 border-t border-zinc-100 pt-4" @if ($errors->hasAny(['environment', 'version', 'commit_reference', 'target_url', 'release_notes', 'rollback_plan', 'release'])) open @endif>
                            <summary class="cursor-pointer text-sm font-medium text-emerald-700">New release</summary>
                            <form method="POST" action="{{ route('projects.releases.store', $project) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                @csrf
                                <div>
                                    <label for="environment" class="form-label">Environment</label>
                                    <select id="environment" name="environment" class="form-input">
                                        <option value="staging" @selected(old('environment') === 'staging')>Staging</option>
                                        <option value="production" @selected(old('environment') === 'production')>Production</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="version" class="form-label">Version</label>
                                    <input id="version" name="version" required maxlength="64" placeholder="1.0.0" value="{{ old('version') }}" class="form-input">
                                    <x-input-error for="version" />
                                </div>
                                <div>
                                    <label for="commit_reference" class="form-label">Commit or tag <span class="font-normal text-zinc-500">(optional)</span></label>
                                    <input id="commit_reference" name="commit_reference" maxlength="100" value="{{ old('commit_reference') }}" class="form-input font-mono">
                                    <x-input-error for="commit_reference" />
                                </div>
                                <div>
                                    <label for="target_url" class="form-label">URL <span class="font-normal text-zinc-500">(blank to use the project's)</span></label>
                                    <input id="target_url" name="target_url" type="url" maxlength="255" value="{{ old('target_url') }}" class="form-input">
                                    <x-input-error for="target_url" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="release_notes" class="form-label">What is in this release</label>
                                    <textarea id="release_notes" name="release_notes" rows="3" required maxlength="5000" class="form-input">{{ old('release_notes') }}</textarea>
                                    <x-input-error for="release_notes" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="rollback_plan" class="form-label">Rollback plan</label>
                                    <textarea id="rollback_plan" name="rollback_plan" rows="3" required maxlength="5000" placeholder="e.g. Restore last night's backup from the host panel and redeploy tag 0.9.2" class="form-input">{{ old('rollback_plan') }}</textarea>
                                    <x-input-error for="rollback_plan" />
                                </div>
                                <div class="sm:col-span-2"><button type="submit" class="btn-primary">Create release</button></div>
                            </form>
                        </details>
                    @endif
                @endcan
            </section>

            <section class="card" aria-labelledby="feedback-heading">
                <h2 id="feedback-heading" class="mb-3 font-semibold">Client feedback and change requests</h2>
                @php($feedback = $project->events->where('event_type', 'client_feedback'))
                @if ($feedback->isEmpty() && $changeRequests->isEmpty())
                    <p class="text-sm text-zinc-600">No feedback recorded yet.</p>
                @endif
                <ul class="space-y-2 text-sm">
                    @foreach ($feedback as $f)
                        <li><span class="font-medium">{{ ($f->metadata['scope'] ?? '') === 'out_of_scope' ? 'Outside scope' : 'Within scope' }}</span> · {{ $f->created_at->format('M j, Y') }} · {{ $f->actorName() }}<span class="block whitespace-pre-line text-zinc-700">{{ $f->note }}</span></li>
                    @endforeach
                    @foreach ($changeRequests as $cr)
                        <li>Change request <a href="{{ route('proposals.show', $cr) }}" class="text-emerald-700 underline">{{ $cr->label() }}</a> <x-status-badge :status="$cr->status" /> {{ App\Support\Money::format($cr->amount_minor, $cr->currency) }}</li>
                    @endforeach
                </ul>
                @can('update', $project)
                    <form method="POST" action="{{ route('projects.feedback', $project) }}" class="mt-4 space-y-3 border-t border-zinc-100 pt-4">
                        @csrf
                        <div>
                            <label for="feedback_note" class="form-label">What the client said</label>
                            <textarea id="feedback_note" name="note" rows="3" required maxlength="2000" class="form-input"></textarea>
                        </div>
                        <fieldset class="space-y-1 text-sm">
                            <legend class="form-label">Is it in the agreed scope?</legend>
                            <label class="flex items-center gap-2"><input type="radio" name="scope" value="in_scope" checked class="text-emerald-700"> Yes, part of the agreed work</label>
                            <label class="flex items-center gap-2"><input type="radio" name="scope" value="out_of_scope" class="text-emerald-700"> No: start a change request with its own price</label>
                        </fieldset>
                        <button type="submit" class="btn-secondary">Record feedback</button>
                    </form>
                @endcan
            </section>

            <section class="card" aria-labelledby="history">
                <h2 id="history" class="mb-3 font-semibold">History</h2>
                <ol class="space-y-3 text-sm">
                    @foreach ($project->events as $event)
                        <li>
                            <p><span class="font-medium">{{ $event->label() }}</span> · {{ $event->actorName() }} · {{ $event->created_at->format('M j, Y H:i') }}</p>
                            @if ($event->note)<p class="whitespace-pre-line text-zinc-700">{{ $event->note }}</p>@endif
                            @if ($event->metadata)
                                <p class="break-all text-xs text-zinc-500">{{ collect($event->metadata)->reject(fn ($v) => $v === null || $v === [])->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? $x : json_encode($x), $v)) : (is_bool($v) ? ($v ? 'yes' : 'no') : $v)))->implode(' · ') }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        <aside class="space-y-6" aria-label="Status and checklist">
            <section class="card" aria-labelledby="blockers">
                <h2 id="blockers" class="mb-2 font-semibold">Blockers</h2>
                @if ($overdue->isEmpty() && $blockedTasks->isEmpty() && $failedChecks->isEmpty() && $failedReleases->isEmpty() && $project->status !== ProjectStatus::Blocked)
                    <p class="text-sm text-zinc-600">Nothing blocking right now.</p>
                @else
                    <ul class="list-disc space-y-1 pl-5 text-sm text-red-800">
                        @if ($project->status === ProjectStatus::Blocked)<li>The project is marked blocked.</li>@endif
                        @foreach ($overdue as $m)<li>Milestone "{{ $m->name }}" is overdue ({{ $m->due_at->format('M j') }}).</li>@endforeach
                        @foreach ($blockedTasks as $t)<li>Task "{{ $t->title }}" is blocked{{ $t->failure_reason ? ': '.$t->failure_reason : '' }}.</li>@endforeach
                        @foreach ($failedChecks as [$r, $c])<li>{{ $r->label() }}: "{{ $c->label }}" failed.</li>@endforeach
                        @foreach ($failedReleases as $r)<li>{{ $r->label() }} failed its health check.</li>@endforeach
                    </ul>
                @endif
            </section>

            @can('update', $project)
                @if ($project->status->manualTransitions() !== [])
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Move the project</h2>
                        <form method="POST" action="{{ route('projects.status', $project) }}" class="space-y-3">
                            @csrf
                            <div>
                                <label for="project_status" class="form-label">To</label>
                                <select id="project_status" name="status" class="form-input">
                                    @foreach ($project->status->manualTransitions() as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="status_note" class="form-label">Note</label>
                                <textarea id="status_note" name="note" rows="2" maxlength="2000" class="form-input" placeholder="Needed for client approval, changes, blocking, hold or cancelling"></textarea>
                            </div>
                            <button type="submit" class="btn-primary w-full">Move</button>
                        </form>
                        <p class="mt-3 text-xs text-zinc-500">Client review needs a verified staging release. Delivered is set when a production release is verified. Closing needs the handover checklist done.</p>
                    </section>
                @endif
            @endcan

            <section class="card" aria-labelledby="maintenance-heading">
                <h2 id="maintenance-heading" class="mb-2 font-semibold">Maintenance</h2>
                @if ($maintenancePlans->isEmpty())
                    <p class="text-sm text-zinc-600">No maintenance plan for this project.</p>
                @else
                    <ul class="space-y-1 text-sm">
                        @foreach ($maintenancePlans as $plan)
                            <li class="flex flex-wrap items-center justify-between gap-2"><a href="{{ route('maintenance.plans.show', $plan) }}" class="text-emerald-700 underline">{{ $plan->plan_name }}</a> <x-status-badge :status="$plan->status" /></li>
                        @endforeach
                    </ul>
                @endif
                @can('create', App\Models\MaintenancePlan::class)
                    <a href="{{ route('maintenance.plans.create', ['project_id' => $project->id]) }}" class="btn-secondary mt-3 w-full">Offer a maintenance plan</a>
                @endcan
            </section>

            <section class="card" aria-labelledby="handover-heading" id="handover">
                <h2 id="handover-heading" class="mb-3 font-semibold">Handover checklist</h2>
                <ul class="space-y-2 text-sm">
                    @foreach ($project->handover_checklist as $i => $item)
                        <li class="flex items-start justify-between gap-2">
                            <span class="{{ $item['done'] ? 'text-zinc-500 line-through' : '' }}">{{ $item['item'] }}@if ($item['done'])<span class="block text-xs no-underline">{{ $item['by'] }}, {{ \Illuminate\Support\Carbon::parse($item['at'])->format('M j') }}</span>@endif</span>
                            @can('update', $project)
                                <form method="POST" action="{{ route('projects.handover', $project) }}">
                                    @csrf
                                    <input type="hidden" name="item" value="{{ $i }}">
                                    <input type="hidden" name="done" value="{{ $item['done'] ? 0 : 1 }}">
                                    <button type="submit" class="btn-secondary px-2 py-1 text-xs" aria-label="{{ $item['done'] ? 'Reopen' : 'Mark done' }}: {{ $item['item'] }}">{{ $item['done'] ? 'Reopen' : 'Done' }}</button>
                                </form>
                            @else
                                <span class="text-xs">{{ $item['done'] ? 'Done' : 'Open' }}</span>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            </section>
        </aside>
    </div>
</x-layouts.app>
