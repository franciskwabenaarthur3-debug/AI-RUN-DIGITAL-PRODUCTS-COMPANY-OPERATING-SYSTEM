<x-layouts.app title="Dashboard">
    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight">{{ $settings->company_name }}</h1>
        <p class="text-sm text-zinc-600">Executive dashboard. Figures shown here come only from recorded data.</p>
    </div>

    <section aria-labelledby="overview" class="mb-8">
        <h2 id="overview" class="sr-only">Overview</h2>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card">
                <dt class="text-sm text-zinc-600">Leads</dt>
                <dd class="mt-1 text-lg font-semibold"><a href="{{ route('leads.index') }}" class="hover:underline">{{ $leadCount }}</a></dd>
                <dd class="text-xs text-zinc-500">{{ $qualifiedCount }} qualified</dd>
            </div>
            <div class="card">
                <dt class="text-sm text-zinc-600">Follow-ups due</dt>
                <dd class="mt-1 text-lg font-semibold {{ $followUpsDue > 0 ? 'text-amber-700' : '' }}"><a href="{{ route('leads.index', ['follow_up' => 'due']) }}" class="hover:underline">{{ $followUpsDue }}</a></dd>
            </div>
            <div class="card">
                <dt class="text-sm text-zinc-600">Proposals awaiting approval</dt>
                <dd class="mt-1 text-lg font-semibold {{ $proposalsAwaitingApproval > 0 ? 'text-amber-700' : '' }}"><a href="{{ route('proposals.index', ['status' => 'pending_approval']) }}" class="hover:underline">{{ $proposalsAwaitingApproval }}</a></dd>
                <dd class="text-xs text-zinc-500"><a href="{{ route('proposals.index', ['status' => 'sent']) }}" class="hover:underline">{{ $proposalsSent }} sent, awaiting an answer</a> · <a href="{{ route('proposals.index', ['status' => 'accepted']) }}" class="hover:underline">{{ $proposalsAccepted }} accepted</a></dd>
            </div>
            <div class="card">
                <dt class="text-sm text-zinc-600">Active customers</dt>
                <dd class="mt-1 text-lg font-semibold"><a href="{{ route('customers.index', ['status' => 'active']) }}" class="hover:underline">{{ $customerCount }}</a></dd>
            </div>
            <div class="card">
                <dt class="text-sm text-zinc-600">Active service packages</dt>
                <dd class="mt-1 text-lg font-semibold"><a href="{{ route('service-packages.index') }}" class="hover:underline">{{ $activePackages }}</a></dd>
            </div>
            <div class="card">
                <dt class="text-sm text-zinc-600">Operating status</dt>
                <dd class="mt-1 text-lg font-semibold {{ $settings->isPaused() ? 'text-amber-700' : 'text-emerald-700' }}">
                    {{ $settings->operating_status->label() }}
                </dd>
            </div>
            <div class="card">
                <dt class="text-sm text-zinc-600">AI spend this month (estimated)</dt>
                <dd class="mt-1 text-lg font-semibold">{{ $aiSpent }}</dd>
                <dd class="text-xs text-zinc-500">Budget limit {{ $aiBudget }}</dd>
                @if ($aiAlert)
                    <dd class="mt-1 text-xs font-medium {{ $aiAlert['level'] === 'exhausted' ? 'text-red-700' : 'text-amber-800' }}">
                        {{ $aiAlert['level'] === 'exhausted' ? 'Budget used up: paid agent runs are stopped.' : $aiAlert['percent'].'% of the budget used.' }}
                    </dd>
                @endif
            </div>
        </dl>
    </section>

    <section aria-labelledby="work" class="card mb-8">
        <div class="mb-3 flex items-center justify-between">
            <h2 id="work" class="font-semibold">Tasks by status</h2>
            <a href="{{ route('tasks.index') }}" class="text-sm font-medium text-emerald-700 underline">All tasks</a>
        </div>
        @if ($tasksByStatus->isEmpty())
            <p class="text-sm text-zinc-600">No tasks yet. <a href="{{ route('agents.index') }}" class="font-medium text-emerald-700 underline">Enable an agent</a> and give it a task.</p>
        @else
            <ul class="flex flex-wrap gap-3 text-sm">
                @foreach (App\Enums\TaskStatus::cases() as $status)
                    @if ($tasksByStatus->has($status->value))
                        <li><a href="{{ route('tasks.index', ['status' => $status->value]) }}" class="inline-flex items-center gap-2 hover:underline"><x-status-badge :status="$status" /> {{ $tasksByStatus[$status->value] }}</a></li>
                    @endif
                @endforeach
            </ul>
        @endif
        <p class="mt-3 text-sm {{ $failedRuns ? 'text-red-700' : 'text-zinc-600' }}">{{ $failedRuns }} failed agent run{{ $failedRuns === 1 ? '' : 's' }} in the last 7 days.</p>
        <p class="mt-1 text-sm"><a href="{{ route('approvals.index', ['status' => 'pending']) }}" class="{{ $pendingApprovals ? 'font-medium text-amber-800' : 'text-zinc-600' }} hover:underline">{{ $pendingApprovals }} approval request{{ $pendingApprovals === 1 ? '' : 's' }} waiting for a decision.</a></p>
        @if ($reconcileApprovals)
            <p class="mt-1 text-sm"><a href="{{ route('approvals.index', ['status' => 'needs_reconciliation']) }}" class="font-medium text-red-700 hover:underline">{{ $reconcileApprovals }} approved action{{ $reconcileApprovals === 1 ? '' : 's' }} with an unknown outcome need reconciling.</a></p>
        @endif
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="getting-started" class="card">
            <h2 id="getting-started" class="mb-3 font-semibold">Getting started</h2>
            <ol class="space-y-2 text-sm">
                <li class="flex gap-2">
                    <span aria-hidden="true">{{ $settingsReviewed ? '✓' : '○' }}</span>
                    <span>Review company settings, currency and AI budget on the <a href="{{ route('settings.edit') }}" class="font-medium text-emerald-700 underline">Settings</a> page.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true">{{ $activePackages > 0 ? '✓' : '○' }}</span>
                    <span>Define <a href="{{ route('service-packages.index') }}" class="font-medium text-emerald-700 underline">service packages</a> with real scope and pricing.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true">{{ $leadCount > 0 ? '✓' : '○' }}</span>
                    <span>Add your first <a href="{{ route('leads.index') }}" class="font-medium text-emerald-700 underline">lead</a>.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true">○</span>
                    <span>Projects, delivery and maintenance are added in later phases and will appear in the menu when they work.</span>
                </li>
            </ol>
        </section>

        @if ($recentAudit !== null)
            <section aria-labelledby="recent-audit" class="card">
                <div class="mb-3 flex items-center justify-between">
                    <h2 id="recent-audit" class="font-semibold">Recent audit events</h2>
                    <a href="{{ route('audit.index') }}" class="text-sm font-medium text-emerald-700 underline">View all</a>
                </div>
                @if ($recentAudit->isEmpty())
                    <p class="text-sm text-zinc-600">No audit events recorded yet.</p>
                @else
                    <ul class="divide-y divide-zinc-100 text-sm">
                        @foreach ($recentAudit as $log)
                            <li class="flex justify-between gap-4 py-2">
                                <span class="font-mono text-xs text-zinc-800">{{ $log->action }}</span>
                                <time datetime="{{ $log->created_at->toIso8601String() }}" class="shrink-0 text-xs text-zinc-500">
                                    {{ $log->created_at->timezone($settings->timezone)->format('M j, H:i') }}
                                </time>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    </div>
</x-layouts.app>
