<x-layouts.app title="Proposals">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Proposals</h1>
            <p class="text-sm text-zinc-600">Latest version of each proposal. Nothing is sent without the founder's approval of the exact version.</p>
        </div>
        @can('create', App\Models\Proposal::class)
            <a href="{{ route('proposals.create') }}" class="btn-primary">New proposal</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('proposals.index') }}" class="card mb-6 flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="sm:w-64">
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-input">
                <option value="">Any status</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected(($filters['status'] ?? null) === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn-primary">Filter</button>
    </form>

    @if ($proposals->isEmpty())
        <div class="card text-sm text-zinc-600">
            @if (array_filter($filters))
                No proposals match this filter. <a href="{{ route('proposals.index') }}" class="font-medium text-emerald-700 underline">Clear filter</a>
            @else
                No proposals yet. Start one from a lead or customer and an active service package.
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-3">Proposal</th>
                        <th scope="col" class="px-4 py-3">For</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3 text-right">Total</th>
                        <th scope="col" class="px-4 py-3">Valid until</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($proposals as $p)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('proposals.show', $p) }}" class="font-medium text-zinc-900 underline-offset-2 hover:underline">{{ $p->title }}</a>
                                <div class="text-xs text-zinc-500">{{ $p->label() }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-700">{{ $p->recipientLabel() }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$p->status" /></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-zinc-700">{{ App\Support\Money::format($p->amount_minor, $p->currency) }}</td>
                            <td class="px-4 py-3 text-zinc-700">{{ $p->expires_at->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $proposals->links() }}</div>
    @endif
</x-layouts.app>
