<x-layouts.app :title="$customer->company_name">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm"><a href="{{ route('customers.index') }}" class="text-emerald-700 underline">Customers</a></p>
            <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight">{{ $customer->company_name }} <x-status-badge :status="$customer->status" /></h1>
        </div>
        @can('update', $customer)
            <a href="{{ route('customers.edit', $customer) }}" class="inline-flex items-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium hover:bg-zinc-50">Edit</a>
        @endcan
    </div>

    <section class="card max-w-3xl">
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-zinc-500">Primary contact</dt><dd>{{ $customer->primary_contact ?? '—' }}</dd></div>
            <div><dt class="text-zinc-500">Email</dt><dd class="break-all">{{ $customer->email ?? '—' }}</dd></div>
            <div><dt class="text-zinc-500">Phone</dt><dd>{{ $customer->phone ?? '—' }}</dd></div>
            <div><dt class="text-zinc-500">Customer since</dt><dd>{{ $customer->created_at->format('M j, Y') }}</dd></div>
            @if ($customer->lead)
                <div><dt class="text-zinc-500">Converted from lead</dt><dd><a href="{{ route('leads.show', $customer->lead) }}" class="text-emerald-700 underline">{{ $customer->lead->company_name }} (#{{ $customer->lead->id }})</a></dd></div>
            @endif
            @can('viewBilling', $customer)
                <div class="sm:col-span-2"><dt class="text-zinc-500">Billing details</dt><dd class="whitespace-pre-line">{{ $customer->billing_details ?? 'None recorded.' }}</dd></div>
            @endcan
        </dl>
    </section>

    @include('proposals._list', [
        'proposals' => App\Models\Proposal::query()->where('customer_id', $customer->id)->when($customer->lead, fn ($q) => $q->orWhere('lead_id', $customer->lead->id))->latest('id')->get(),
        'create' => $customer->status === App\Enums\CustomerStatus::Active ? ['customer_id' => $customer->id] : null,
        'class' => 'mt-6 max-w-3xl',
    ])
</x-layouts.app>
