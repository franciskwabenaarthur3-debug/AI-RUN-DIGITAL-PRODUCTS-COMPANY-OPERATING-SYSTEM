{{-- Proposals for a lead or customer: $proposals (latest first), $create (query for a new proposal) --}}
<section class="card {{ $class ?? '' }}" aria-labelledby="proposals">
    <div class="mb-3 flex items-center justify-between">
        <h2 id="proposals" class="font-semibold">Proposals</h2>
        @if ($create)
            @can('create', App\Models\Proposal::class)
                <a href="{{ route('proposals.create', $create) }}" class="text-sm font-medium text-emerald-700 underline">New proposal</a>
            @endcan
        @endif
    </div>
    @forelse ($proposals as $p)
        <p class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 py-2 text-sm first-of-type:border-0">
            <a href="{{ route('proposals.show', $p) }}" class="underline">{{ $p->label() }}: {{ $p->title }}</a>
            <span class="flex items-center gap-2"><x-status-badge :status="$p->status" /> {{ App\Support\Money::format($p->amount_minor, $p->currency) }}</span>
        </p>
    @empty
        <p class="text-sm text-zinc-600">No proposals yet.</p>
    @endforelse
</section>
