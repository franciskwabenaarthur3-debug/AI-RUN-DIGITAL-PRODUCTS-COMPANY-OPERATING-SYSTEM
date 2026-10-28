@php
    use App\Enums\MaintenancePlanStatus;
    $money = App\Support\Money::format($plan->amount_minor, $plan->currency);
@endphp
<x-layouts.app :title="$plan->plan_name">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm"><a href="{{ route('maintenance.index') }}" class="text-emerald-700 underline">Maintenance</a></p>
            <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">{{ $plan->plan_name }} <x-status-badge :status="$plan->status" /></h1>
            <p class="text-sm text-zinc-600">For <a href="{{ route('customers.show', $plan->customer) }}" class="underline">{{ $plan->customer->company_name }}</a> · {{ $money }} {{ $plan->billing_frequency->value }} · {{ strtolower($plan->service_interval->label()) }} visits</p>
        </div>
        @can('update', $plan)
            <a href="{{ route('maintenance.plans.edit', $plan) }}" class="btn-secondary">Edit plan</a>
        @endcan
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif
    @if ($plan->renewalDue())
        <p role="note" class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">The term ends {{ $plan->term_ends_at->format('M j, Y') }}. Agree a renewal with the client, then record it here.</p>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="details">
                <h2 id="details" class="mb-4 font-semibold">Plan</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-zinc-500">Site</dt><dd class="break-all">
                        @if ($plan->project)<a href="{{ route('projects.show', $plan->project) }}" class="text-emerald-700 underline">{{ $plan->project->name }}</a>@if ($plan->siteUrl())<span class="block text-xs">{{ $plan->siteUrl() }}</span>@else<span class="block text-xs text-zinc-500">No production URL; the health check is unavailable</span>@endif @else — @endif
                    </dd></div>
                    <div><dt class="text-zinc-500">Next visit</dt><dd>{{ $plan->next_service_at?->format('M j, Y') ?? '—' }}</dd></div>
                    <div><dt class="text-zinc-500">Term ends</dt><dd>{{ $plan->term_ends_at->format('M j, Y') }}</dd></div>
                    <div><dt class="text-zinc-500">Price</dt><dd>{{ $money }} billed {{ $plan->billing_frequency->value }} (recorded here; invoicing is outside Company OS)</dd></div>
                    <div class="sm:col-span-2"><dt class="text-zinc-500">Visit checklist</dt><dd><ul class="list-disc pl-5">@foreach ($plan->checklist as $item)<li>{{ $item }}</li>@endforeach</ul></dd></div>
                    @if ($plan->notes)<div class="sm:col-span-2"><dt class="text-zinc-500">Notes</dt><dd class="whitespace-pre-line">{{ $plan->notes }}</dd></div>@endif
                </dl>
            </section>

            <section class="card" aria-labelledby="visits">
                <h2 id="visits" class="mb-3 font-semibold">Visits and service history</h2>
                @if ($plan->tasks->isEmpty())
                    <p class="text-sm text-zinc-600">No visits yet. The first is created {{ config('company_os.maintenance.schedule_ahead_days') }} days before {{ $plan->next_service_at?->format('M j, Y') ?? 'the next service date' }}.</p>
                @else
                    <ul class="divide-y divide-zinc-100 text-sm">
                        @foreach ($plan->tasks as $task)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span><a href="{{ route('maintenance.tasks.show', $task) }}" class="font-medium text-zinc-900 underline-offset-2 hover:underline">{{ $task->title }}</a><span class="block text-xs {{ $task->isOverdue() ? 'font-medium text-red-700' : 'text-zinc-500' }}">{{ $task->scheduled_at->format('M j, Y') }} · {{ $task->findings->count() }} {{ Str::plural('finding', $task->findings->count()) }}</span></span>
                                <x-status-badge :status="$task->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="space-y-6" aria-label="Actions">
            @can('operate', $plan)
                @if ($plan->status === MaintenancePlanStatus::Active)
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Schedule an extra visit</h2>
                        <form method="POST" action="{{ route('maintenance.plans.schedule', $plan) }}" class="space-y-3">
                            @csrf
                            <div><label for="visit_date" class="form-label">Date</label><input id="visit_date" name="date" type="date" required min="{{ today()->toDateString() }}" class="form-input"></div>
                            <div><label for="visit_title" class="form-label">Title <span class="font-normal text-zinc-500">(optional)</span></label><input id="visit_title" name="title" maxlength="200" class="form-input"></div>
                            <button type="submit" class="btn-secondary w-full">Schedule</button>
                        </form>
                    </section>
                @endif
            @endcan
            @can('update', $plan)
                @if (in_array($plan->status, [MaintenancePlanStatus::Active, MaintenancePlanStatus::Ended], true))
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Record a renewal</h2>
                        <form method="POST" action="{{ route('maintenance.plans.renew', $plan) }}" class="space-y-3">
                            @csrf
                            <div><label for="renew_end" class="form-label">New end date</label><input id="renew_end" name="term_ends_at" type="date" required value="{{ $plan->term_ends_at->copy()->addYear()->toDateString() }}" class="form-input"></div>
                            <div><label for="renew_evidence" class="form-label">Client's agreement</label><textarea id="renew_evidence" name="evidence" rows="2" required maxlength="2000" placeholder="e.g. Email from the client on 2 Nov renewing for a year" class="form-input"></textarea></div>
                            <button type="submit" class="btn-primary w-full">Record renewal</button>
                        </form>
                    </section>
                @endif
                @php($next = match ($plan->status) { MaintenancePlanStatus::Active => [MaintenancePlanStatus::Paused, MaintenancePlanStatus::Cancelled, MaintenancePlanStatus::Ended], MaintenancePlanStatus::Paused => [MaintenancePlanStatus::Active, MaintenancePlanStatus::Cancelled], default => [] })
                @if ($next)
                    <section class="card">
                        <h2 class="mb-2 font-semibold">Change status</h2>
                        <form method="POST" action="{{ route('maintenance.plans.status', $plan) }}" class="space-y-3">
                            @csrf
                            <div><label for="plan_status" class="form-label">To</label><select id="plan_status" name="status" class="form-input">@foreach ($next as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
                            <div><label for="plan_reason" class="form-label">Reason</label><input id="plan_reason" name="reason" required maxlength="2000" class="form-input"></div>
                            <button type="submit" class="btn-secondary w-full">Change</button>
                        </form>
                    </section>
                @endif
            @endcan
        </aside>
    </div>
</x-layouts.app>
