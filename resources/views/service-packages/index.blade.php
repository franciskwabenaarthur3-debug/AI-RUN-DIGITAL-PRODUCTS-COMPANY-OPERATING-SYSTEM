<x-layouts.app title="Service packages">
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Service packages</h1>
            <p class="text-sm text-zinc-600">Approved offerings that proposals will be built from. Only the founder can change scope and prices.</p>
        </div>
        @can('create', App\Models\ServicePackage::class)
            <a href="{{ route('service-packages.create') }}" class="btn-primary">New package</a>
        @endcan
    </div>

    @if ($packages->isEmpty())
        <div class="card text-sm text-zinc-600">No service packages yet. Define your first offering with its scope, exclusions and price.</div>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($packages as $package)
                <article class="card {{ $package->is_active ? '' : 'opacity-75' }}">
                    <div class="mb-2 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold">{{ $package->name }}</h2>
                            <p class="text-xs text-zinc-500">{{ $package->slug }}</p>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $package->is_active ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-zinc-100 text-zinc-700 ring-zinc-500/20' }}">{{ $package->is_active ? 'Active' : 'Inactive' }}</span>
                    </div>
                    <p class="text-lg font-semibold">{{ App\Support\Money::format($package->base_price_minor, $package->currency) }}</p>
                    <p class="mb-3 text-xs text-zinc-500">{{ $package->estimated_days }} days · {{ $package->revision_limit }} revision{{ $package->revision_limit === 1 ? '' : 's' }}</p>
                    @if ($package->description)
                        <p class="mb-3 text-sm text-zinc-700">{{ $package->description }}</p>
                    @endif
                    <div class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Included</h3>
                            <ul class="mt-1 list-disc pl-5">@foreach ($package->scope as $item)<li>{{ $item }}</li>@endforeach</ul>
                        </div>
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Not included</h3>
                            @if ($package->exclusions)
                                <ul class="mt-1 list-disc pl-5">@foreach ($package->exclusions as $item)<li>{{ $item }}</li>@endforeach</ul>
                            @else
                                <p class="mt-1 text-zinc-500">None listed</p>
                            @endif
                        </div>
                    </div>
                    @can('update', $package)
                        <div class="mt-4 text-right"><a href="{{ route('service-packages.edit', $package) }}" class="text-sm font-medium text-emerald-700 underline">Edit</a></div>
                    @endcan
                </article>
            @endforeach
        </div>
        <div class="mt-4">{{ $packages->links() }}</div>
    @endif
</x-layouts.app>
