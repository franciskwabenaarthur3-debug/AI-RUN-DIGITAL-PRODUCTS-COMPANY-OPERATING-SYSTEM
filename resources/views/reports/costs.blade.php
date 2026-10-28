@php $m = fn ($minor) => App\Support\Money::format((int) $minor, $currency); @endphp
<x-layouts.app title="AI costs">
    <div class="mb-6">
        <p class="text-sm"><a href="{{ route('reports.index') }}" class="text-emerald-700 underline">Reports</a></p>
        <h1 class="text-2xl font-semibold tracking-tight">AI usage and cost</h1>
        <p class="text-sm text-zinc-600">From recorded model calls. Where the provider did not report a cost, the estimate is used and marked. Hosting and other expenses are not recorded in Company OS.</p>
    </div>

    <section class="card mb-6" aria-labelledby="by-month">
        <h2 id="by-month" class="mb-3 font-semibold">Last six months</h2>
        <table class="w-full text-left text-sm">
            <thead class="text-xs text-zinc-500"><tr><th class="py-1 font-medium">Month</th><th class="py-1 font-medium">Calls</th><th class="py-1 font-medium">Tokens</th><th class="py-1 font-medium">Cost</th></tr></thead>
            <tbody class="divide-y divide-zinc-100">
                @foreach ($months as $row)
                    <tr>
                        <td class="py-1.5"><a href="{{ route('reports.costs', ['month' => $row['month']->format('Y-m')]) }}" @if ($row['month']->equalTo($month)) aria-current="page" class="font-semibold underline" @else class="text-emerald-700 underline" @endif>{{ $row['month']->format('F Y') }}</a></td>
                        <td class="py-1.5">{{ number_format($row['calls']) }}</td>
                        <td class="py-1.5">{{ number_format($row['tokens']) }}</td>
                        <td class="py-1.5">{{ $m($row['total']) }}{{ $row['estimated'] ? ' (includes estimates)' : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="by-agent">
            <h2 id="by-agent" class="mb-3 font-semibold">{{ $month->format('F Y') }} by agent</h2>
            @if ($byAgent->isEmpty())
                <p class="text-sm text-zinc-600">No model calls recorded this month.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-zinc-500"><tr><th class="py-1 font-medium">Agent</th><th class="py-1 font-medium">Calls</th><th class="py-1 font-medium">Cost</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100">@foreach ($byAgent->sortByDesc('total') as $r)<tr><td class="py-1.5">{{ $r['agent'] }}</td><td class="py-1.5">{{ number_format($r['calls']) }}</td><td class="py-1.5">{{ $m($r['total']) }}</td></tr>@endforeach</tbody>
                </table>
            @endif
        </section>
        <section class="card" aria-labelledby="by-model">
            <h2 id="by-model" class="mb-3 font-semibold">{{ $month->format('F Y') }} by model</h2>
            @if ($byModel->isEmpty())
                <p class="text-sm text-zinc-600">No model calls recorded this month.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-zinc-500"><tr><th class="py-1 font-medium">Model</th><th class="py-1 font-medium">Calls</th><th class="py-1 font-medium">Tokens in / out</th><th class="py-1 font-medium">Cost</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100">@foreach ($byModel->sortByDesc('total') as $r)<tr><td class="py-1.5 font-mono text-xs">{{ $r->model }}</td><td class="py-1.5">{{ number_format($r->calls) }}</td><td class="py-1.5">{{ number_format($r->input_tokens) }} / {{ number_format($r->output_tokens) }}</td><td class="py-1.5">{{ $m($r->total) }}</td></tr>@endforeach</tbody>
                </table>
            @endif
        </section>
    </div>
</x-layouts.app>
