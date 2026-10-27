<x-layouts.app title="Leads">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Leads</h1>
            <p class="text-sm text-zinc-600">Prospects and their qualification status.</p>
        </div>
        @can('create', App\Models\Lead::class)
            <a href="{{ route('leads.create') }}" class="btn-primary">New lead</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('leads.index') }}" class="card mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
        <div class="lg:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Company, contact, email or domain" class="form-input">
        </div>
        <div>
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-input">
                <option value="">Any status</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s->value }}" @selected(($filters['status'] ?? null) === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="source" class="form-label">Source</label>
            <select id="source" name="source" class="form-input">
                <option value="">Any source</option>
                @foreach ($sources as $s)
                    <option value="{{ $s->value }}" @selected(($filters['source'] ?? null) === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-zinc-700">
                <input type="checkbox" name="follow_up" value="due" @checked(($filters['follow_up'] ?? null) === 'due') class="rounded border-zinc-300 text-emerald-700">
                Follow-up due
            </label>
            <button type="submit" class="btn-primary">Filter</button>
        </div>
    </form>

    @if ($leads->isEmpty())
        <div class="card text-sm text-zinc-600">
            @if (array_filter($filters))
                No leads match these filters. <a href="{{ route('leads.index') }}" class="font-medium text-emerald-700 underline">Clear filters</a>
            @else
                No leads yet. Add your first prospect to start tracking qualification and follow-ups.
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-3">Company</th>
                        <th scope="col" class="px-4 py-3">Contact</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3">Score</th>
                        <th scope="col" class="px-4 py-3">Next follow-up</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($leads as $lead)
                        <tr>
                            <td class="px-4 py-3">
                                <a href="{{ route('leads.show', $lead) }}" class="font-medium text-zinc-900 underline-offset-2 hover:underline">{{ $lead->company_name }}</a>
                                @if ($lead->website_domain)
                                    <div class="text-xs text-zinc-500">{{ $lead->website_domain }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-zinc-700">
                                {{ $lead->contact_name ?? '—' }}
                                @if ($lead->do_not_contact)
                                    <div class="text-xs font-medium text-red-700">Do not contact</div>
                                @endif
                            </td>
                            <td class="px-4 py-3"><x-status-badge :status="$lead->status" /></td>
                            <td class="px-4 py-3 text-zinc-700">{{ $lead->qualification_score ?? '—' }}</td>
                            <td class="px-4 py-3 {{ $lead->isFollowUpDue() ? 'font-medium text-amber-700' : 'text-zinc-700' }}">
                                {{ $lead->next_follow_up_at?->format('M j, Y H:i') ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $leads->links() }}</div>
    @endif
</x-layouts.app>
