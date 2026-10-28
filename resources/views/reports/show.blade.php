<x-layouts.app :title="$stored->period->label().' report · '.$stored->label()">
    <div class="mb-6">
        <p class="text-sm"><a href="{{ route('reports.index') }}" class="text-emerald-700 underline">Reports</a></p>
        <h1 class="text-2xl font-semibold tracking-tight">{{ $stored->period->label() }} report: {{ $stored->label() }}</h1>
        <p class="text-sm text-zinc-600">{{ $stored->period_start->format('M j') }} to {{ $stored->period_end->format('M j, Y') }} · generated {{ $stored->generated_at->format('M j, Y H:i') }} and kept as it was</p>
    </div>
    @include('reports._report')
</x-layouts.app>
