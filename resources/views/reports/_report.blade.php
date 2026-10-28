@php
    $m = fn ($minor, $currency) => App\Support\Money::format((int) $minor, $currency);
    $pct = fn ($v) => $v === null ? 'n/a' : $v.'%';
    $cur = $report['costs']['currency'];
@endphp
<div class="space-y-6">
    <section class="card" aria-labelledby="priorities">
        <h2 id="priorities" class="mb-2 font-semibold">Recommended priorities</h2>
        @if ($report['priorities'])
            <ol class="list-decimal space-y-1 pl-5 text-sm">@foreach ($report['priorities'] as $p)<li>{{ $p }}</li>@endforeach</ol>
        @else
            <p class="text-sm text-zinc-600">Nothing needs attention by these rules.</p>
        @endif
        <p class="mt-2 text-xs text-zinc-500">From fixed rules over the numbers below. Advisory only: nothing here spends money or changes plans.</p>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="r-sales">
            <h2 id="r-sales" class="mb-3 font-semibold">Sales</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">New leads</dt><dd>{{ $report['sales']['leads_created'] }}</dd></div>
                <div><dt class="text-zinc-500">Qualified-lead rate</dt><dd>{{ $pct($report['sales']['qualified_rate_percent']) }} ({{ $report['sales']['leads_qualified'] }})</dd></div>
                <div><dt class="text-zinc-500">Proposals awaiting approval*</dt><dd>{{ $report['sales']['proposals_awaiting_approval'] }}</dd></div>
                <div><dt class="text-zinc-500">Proposals sent</dt><dd>{{ $report['sales']['proposals_sent'] }}</dd></div>
                <div><dt class="text-zinc-500">Proposal approval rate</dt><dd>{{ $pct($report['sales']['proposal_approval_rate_percent']) }}</dd></div>
                <div><dt class="text-zinc-500">Accepted</dt><dd>{{ $report['sales']['proposals_accepted'] }}</dd></div>
                <div><dt class="text-zinc-500">Conversion rate</dt><dd>{{ $pct($report['sales']['proposal_conversion_rate_percent']) }}</dd></div>
                <div><dt class="text-zinc-500">Accepted value (agreed, not received)</dt><dd>@forelse ($report['sales']['accepted_value_minor'] as $c => $v)<span class="block">{{ $m($v, $c) }}</span>@empty — @endforelse</dd></div>
            </dl>
        </section>

        <section class="card" aria-labelledby="r-projects">
            <h2 id="r-projects" class="mb-3 font-semibold">Projects and delivery</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">Active*</dt><dd>{{ $report['projects']['active'] }}</dd></div>
                <div><dt class="text-zinc-500">Started</dt><dd>{{ $report['projects']['created'] }}</dd></div>
                <div><dt class="text-zinc-500">Delivered</dt><dd>{{ $report['projects']['delivered'] }}</dd></div>
                <div><dt class="text-zinc-500">On-time rate</dt><dd>{{ $pct($report['projects']['on_time_rate_percent']) }}</dd></div>
                <div><dt class="text-zinc-500">Average delivery time</dt><dd>{{ $report['projects']['average_delivery_days'] === null ? 'n/a' : $report['projects']['average_delivery_days'].' days' }}</dd></div>
                <div><dt class="text-zinc-500">Changes requested by clients</dt><dd>{{ $report['projects']['changes_requested'] }}</dd></div>
                <div><dt class="text-zinc-500">Overdue milestones*</dt><dd>{{ $report['projects']['overdue_milestones'] }}</dd></div>
                <div><dt class="text-zinc-500">Releases failing their health check*</dt><dd>{{ $report['projects']['releases_failed_verification'] }}</dd></div>
            </dl>
        </section>

        <section class="card" aria-labelledby="r-maint">
            <h2 id="r-maint" class="mb-3 font-semibold">Maintenance</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">Active plans*</dt><dd>{{ $report['maintenance']['active_plans'] }}</dd></div>
                <div><dt class="text-zinc-500">Visits due</dt><dd>{{ $report['maintenance']['visits_due'] }}</dd></div>
                <div><dt class="text-zinc-500">Completed</dt><dd>{{ $report['maintenance']['visits_completed'] }}</dd></div>
                <div><dt class="text-zinc-500">Failed</dt><dd>{{ $report['maintenance']['visits_failed'] }}</dd></div>
                <div><dt class="text-zinc-500">Overdue*</dt><dd>{{ $report['maintenance']['visits_overdue'] }}</dd></div>
                <div><dt class="text-zinc-500">Open findings*</dt><dd>{{ $report['maintenance']['findings_open'] }}</dd></div>
                <div><dt class="text-zinc-500">Findings recorded</dt><dd>@forelse ($report['maintenance']['findings_by_severity'] as $s => $n)<span class="block">{{ ucfirst($s) }}: {{ $n }}</span>@empty 0 @endforelse</dd></div>
                <div><dt class="text-zinc-500">Renewals due soon*</dt><dd>{{ $report['maintenance']['renewals_due'] }}</dd></div>
                <div class="col-span-2"><dt class="text-zinc-500">Contracted plan prices* (not money received)</dt><dd>@forelse ($report['maintenance']['contracted_value_minor'] as $key => $v)@php([$c, $freq] = explode(' ', $key, 2))<span class="block">{{ $m($v, $c) }} {{ $freq }}</span>@empty — @endforelse</dd></div>
            </dl>
        </section>

        <section class="card" aria-labelledby="r-agents">
            <h2 id="r-agents" class="mb-3 font-semibold">Tasks and agents</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">Blocked tasks*</dt><dd>{{ $report['tasks']['blocked'] }}</dd></div>
                <div><dt class="text-zinc-500">Awaiting review*</dt><dd>{{ $report['tasks']['awaiting_review'] }}</dd></div>
                <div><dt class="text-zinc-500">Agent runs</dt><dd>{{ $report['tasks']['agent_runs'] }} ({{ $report['tasks']['agent_runs_failed'] }} failed)</dd></div>
                <div><dt class="text-zinc-500">Agent task success rate</dt><dd>{{ $pct($report['tasks']['agent_success_rate_percent']) }}</dd></div>
            </dl>
            @if ($report['tasks']['by_agent'])
                <table class="mt-4 w-full text-left text-sm">
                    <thead class="text-xs text-zinc-500"><tr><th class="py-1 font-medium">Agent</th><th class="py-1 font-medium">Runs</th><th class="py-1 font-medium">Failed</th><th class="py-1 font-medium">AI cost</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100">@foreach ($report['tasks']['by_agent'] as $a)<tr><td class="py-1">{{ $a['agent'] }}</td><td class="py-1">{{ $a['runs'] }}</td><td class="py-1">{{ $a['failed'] }}</td><td class="py-1">{{ $m($a['cost_minor'], $cur) }}</td></tr>@endforeach</tbody>
                </table>
            @endif
        </section>

        <section class="card" aria-labelledby="r-costs">
            <h2 id="r-costs" class="mb-3 font-semibold">AI cost</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">AI usage</dt><dd>{{ $m($report['costs']['ai_cost_minor'], $cur) }}{{ $report['costs']['ai_cost_is_estimated'] ? ' (includes estimates)' : '' }}</dd></div>
                <div><dt class="text-zinc-500">Per completed agent task</dt><dd>{{ $report['costs']['ai_cost_per_completed_task_minor'] === null ? 'n/a' : $m($report['costs']['ai_cost_per_completed_task_minor'], $cur) }} ({{ $report['costs']['agent_tasks_completed'] }} tasks)</dd></div>
                <div class="col-span-2"><dt class="text-zinc-500">By model</dt><dd>@forelse ($report['costs']['ai_cost_by_model'] as $model => $v)<span class="block">{{ $model }}: {{ $m($v, $cur) }}</span>@empty No usage recorded. @endforelse</dd></div>
            </dl>
        </section>

        <section class="card" aria-labelledby="r-errors">
            <h2 id="r-errors" class="mb-3 font-semibold">Errors and failed workflows</h2>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">Failed tool calls</dt><dd>{{ $report['errors']['failed_tool_calls'] }}</dd></div>
                <div><dt class="text-zinc-500">Failed approved actions</dt><dd>{{ $report['errors']['failed_approvals'] }}</dd></div>
                <div><dt class="text-zinc-500">Needs reconciliation*</dt><dd>{{ $report['errors']['needs_reconciliation'] }}</dd></div>
                <div><dt class="text-zinc-500">Alerts raised</dt><dd>{{ $report['errors']['alerts_raised'] }} ({{ $report['errors']['alerts_open'] }} open*)</dd></div>
            </dl>
        </section>
    </div>

    <section class="card text-sm" aria-labelledby="r-missing">
        <h2 id="r-missing" class="mb-2 font-semibold">Not recorded in Company OS</h2>
        <p class="text-zinc-600">These are not tracked here, so they are not reported: {{ implode(', ', array_map('lcfirst', $report['not_recorded'])) }}.</p>
        <p class="mt-2 text-xs text-zinc-500">* Counted when the report was generated. Everything else is counted within the period.</p>
    </section>
</div>
