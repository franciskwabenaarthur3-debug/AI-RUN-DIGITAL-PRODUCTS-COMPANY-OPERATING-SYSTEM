<x-layouts.app :title="$package->exists ? 'Edit package' : 'New package'">
    <h1 class="mb-6 text-2xl font-semibold tracking-tight">{{ $package->exists ? 'Edit '.$package->name : 'New service package' }}</h1>

    <form method="POST" action="{{ $package->exists ? route('service-packages.update', $package) : route('service-packages.store') }}" class="card max-w-3xl space-y-5">
        @csrf
        @if ($package->exists) @method('PUT') @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="name" class="form-label">Name</label>
                <input id="name" name="name" required maxlength="150" value="{{ old('name', $package->name) }}" class="form-input">
                <x-input-error for="name" />
            </div>
            <div>
                <label for="slug" class="form-label">Slug <span class="font-normal text-zinc-500">(blank to generate)</span></label>
                <input id="slug" name="slug" maxlength="150" value="{{ old('slug', $package->slug) }}" class="form-input">
                <x-input-error for="slug" />
            </div>
            <div class="sm:col-span-2">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" rows="2" maxlength="5000" class="form-input">{{ old('description', $package->description) }}</textarea>
                <x-input-error for="description" />
            </div>
            <div>
                <label for="scope" class="form-label">Included scope <span class="font-normal text-zinc-500">(one item per line)</span></label>
                <textarea id="scope" name="scope" rows="6" required class="form-input">{{ old('scope', implode("\n", $package->scope ?? [])) }}</textarea>
                <x-input-error for="scope" />
            </div>
            <div>
                <label for="exclusions" class="form-label">Exclusions <span class="font-normal text-zinc-500">(one item per line)</span></label>
                <textarea id="exclusions" name="exclusions" rows="6" class="form-input">{{ old('exclusions', implode("\n", $package->exclusions ?? [])) }}</textarea>
                <x-input-error for="exclusions" />
            </div>
            <div>
                <label for="base_price" class="form-label">Base price</label>
                <input id="base_price" name="base_price" inputmode="decimal" required value="{{ old('base_price', $price) }}" placeholder="450.00" class="form-input">
                <x-input-error for="base_price" />
            </div>
            <div>
                <label for="currency" class="form-label">Currency</label>
                <select id="currency" name="currency" class="form-input">
                    @foreach ($currencies as $c)
                        <option value="{{ $c }}" @selected(old('currency', $package->currency) === $c)>{{ $c }}</option>
                    @endforeach
                </select>
                <x-input-error for="currency" />
            </div>
            <div>
                <label for="estimated_days" class="form-label">Estimated delivery (days)</label>
                <input id="estimated_days" name="estimated_days" type="number" min="1" max="365" required value="{{ old('estimated_days', $package->estimated_days) }}" class="form-input">
                <x-input-error for="estimated_days" />
            </div>
            <div>
                <label for="revision_limit" class="form-label">Included revisions</label>
                <input id="revision_limit" name="revision_limit" type="number" min="0" max="50" required value="{{ old('revision_limit', $package->revision_limit) }}" class="form-input">
                <x-input-error for="revision_limit" />
            </div>
        </div>

        <input type="hidden" name="is_active" value="0">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->is_active)) class="rounded border-zinc-300 text-emerald-700">
            Active (available for new proposals)
        </label>

        <div class="flex justify-end gap-3">
            <a href="{{ route('service-packages.index') }}" class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100">Cancel</a>
            <button type="submit" class="btn-primary">{{ $package->exists ? 'Save changes' : 'Create package' }}</button>
        </div>
    </form>
</x-layouts.app>
