@php use App\Enums\ReportPeriod; @endphp
<x-layouts.app title="Reports">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Operating report</h1>
            <p class="text-sm text-zinc-600">{{ $period->label() }} so far: {{ \Illuminate\Support\Carbon::parse($report['from'])->format('M j') }} to {{ \Illuminate\Support\Carbon::parse($report['to'])->format('M j, Y') }}. Counted from Company OS records.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <nav aria-label="Period" class="flex gap-1">
                @foreach (ReportPeriod::cases() as $p)
                    <a href="{{ route('reports.index', ['period' => $p->value]) }}" @if ($p === $period) aria-current="page" @endif class="{{ $p === $period ? 'btn-primary' : 'btn-secondary' }} px-3 py-1.5 text-sm">{{ $p->label() }}</a>
                @endforeach
            </nav>
            <a href="{{ route('reports.costs') }}" class="btn-secondary px-3 py-1.5 text-sm">AI costs</a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-4">
        <div class="lg:col-span-3">@include('reports._report')</div>
        <aside class="card h-fit" aria-labelledby="stored">
            <h2 id="stored" class="mb-2 font-semibold">Stored reports</h2>
            @if ($stored->isEmpty())
                <p class="text-sm text-zinc-600">None yet. A report is stored for each finished day, week and month by the scheduled report job.</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($stored as $r)
                        <li><a href="{{ route('reports.show', $r) }}" class="text-emerald-700 underline">{{ $r->label() }}</a> <span class="text-xs text-zinc-500">{{ $r->period->label() }}</span></li>
                    @endforeach
                </ul>
            @endif
        </aside>
    </div>
</x-layouts.app>
