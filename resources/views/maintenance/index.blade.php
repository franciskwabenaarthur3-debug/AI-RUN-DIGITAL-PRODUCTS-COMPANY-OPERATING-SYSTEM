<x-layouts.app title="Maintenance">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Maintenance</h1>
            <p class="text-sm text-zinc-600">Plans schedule visits before each service date. Visits run non-destructive checks; changes to a live site need the founder's approval.</p>
        </div>
        @can('create', App\Models\MaintenancePlan::class)
            <a href="{{ route('maintenance.plans.create') }}" class="btn-primary">New plan</a>
        @endcan
    </div>

    <section class="card mb-6" aria-labelledby="due">
        <h2 id="due" class="mb-3 font-semibold">Visits due in the next 14 days</h2>
        @if ($due->isEmpty())
            <p class="text-sm text-zinc-600">Nothing due.</p>
        @else
            <ul class="divide-y divide-zinc-100 text-sm">
                @foreach ($due as $task)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><a href="{{ route('maintenance.tasks.show', $task) }}" class="font-medium text-zinc-900 underline-offset-2 hover:underline">{{ $task->title }}</a><span class="block text-xs text-zinc-500">{{ $task->plan->customer->company_name }}</span></span>
                        <span class="flex items-center gap-2">
                            <span class="text-xs {{ $task->isOverdue() ? 'font-medium text-red-700' : 'text-zinc-600' }}">{{ $task->isOverdue() ? 'Overdue since ' : '' }}{{ $task->scheduled_at->format('M j, Y') }}</span>
                            <x-status-badge :status="$task->status" />
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="plans" class="mb-6">
        <h2 id="plans" class="mb-3 font-semibold">Plans</h2>
        @if ($plans->isEmpty())
            <div class="card text-sm text-zinc-600">No maintenance plans yet. Offer a plan when a project is delivered, then record it here once the client agrees.</div>
        @else
            <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                        <tr><th scope="col" class="px-4 py-3">Plan</th><th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3 text-right">Price</th><th scope="col" class="px-4 py-3">Next visit</th><th scope="col" class="px-4 py-3">Term ends</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100">
                        @foreach ($plans as $plan)
                            <tr>
                                <td class="px-4 py-3"><a href="{{ route('maintenance.plans.show', $plan) }}" class="font-medium text-zinc-900 underline-offset-2 hover:underline">{{ $plan->plan_name }}</a><span class="block text-xs text-zinc-500">{{ $plan->customer->company_name }}</span></td>
                                <td class="px-4 py-3"><x-status-badge :status="$plan->status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ App\Support\Money::format($plan->amount_minor, $plan->currency) }} <span class="text-xs text-zinc-500">{{ $plan->billing_frequency->value }}</span></td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $plan->next_service_at?->format('M j, Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 {{ $plan->renewalDue() ? 'font-medium text-amber-800' : '' }}">{{ $plan->term_ends_at->format('M j, Y') }}@if ($plan->renewalDue())<span class="block text-xs">Renewal due</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="card" aria-labelledby="history">
        <h2 id="history" class="mb-3 font-semibold">Recent service history</h2>
        @if ($recent->isEmpty())
            <p class="text-sm text-zinc-600">No finished visits yet.</p>
        @else
            <ul class="divide-y divide-zinc-100 text-sm">
                @foreach ($recent as $task)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><a href="{{ route('maintenance.tasks.show', $task) }}" class="text-zinc-900 underline-offset-2 hover:underline">{{ $task->title }}</a><span class="block text-xs text-zinc-500">{{ $task->plan->customer->company_name }} · {{ ($task->completed_at ?? $task->updated_at)->format('M j, Y') }}</span></span>
                        <x-status-badge :status="$task->status" />
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-layouts.app>
