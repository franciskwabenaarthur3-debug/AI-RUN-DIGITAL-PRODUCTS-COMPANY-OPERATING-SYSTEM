<x-layouts.app title="Customers">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Customers</h1>
            <p class="text-sm text-zinc-600">Companies you work with. Converted leads appear here automatically.</p>
        </div>
        @can('create', App\Models\Customer::class)
            <a href="{{ route('customers.create') }}" class="btn-primary">New customer</a>
        @endcan
    </div>

    <form method="GET" action="{{ route('customers.index') }}" class="card mb-6 grid gap-3 sm:grid-cols-4 sm:items-end">
        <div class="sm:col-span-2">
            <label for="q" class="form-label">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Company, contact or email" class="form-input">
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
        <div><button type="submit" class="btn-primary">Filter</button></div>
    </form>

    @if ($customers->isEmpty())
        <div class="card text-sm text-zinc-600">
            {{ array_filter($filters) ? 'No customers match these filters.' : 'No customers yet. Convert a lead or add a customer directly.' }}
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600">
                    <tr>
                        <th scope="col" class="px-4 py-3">Company</th>
                        <th scope="col" class="px-4 py-3">Primary contact</th>
                        <th scope="col" class="px-4 py-3">Email</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @foreach ($customers as $customer)
                        <tr>
                            <td class="px-4 py-3"><a href="{{ route('customers.show', $customer) }}" class="font-medium underline-offset-2 hover:underline">{{ $customer->company_name }}</a></td>
                            <td class="px-4 py-3 text-zinc-700">{{ $customer->primary_contact ?? '—' }}</td>
                            <td class="px-4 py-3 text-zinc-700">{{ $customer->email ?? '—' }}</td>
                            <td class="px-4 py-3"><x-status-badge :status="$customer->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $customers->links() }}</div>
    @endif
</x-layouts.app>
