<x-layouts.app :title="$customer->exists ? 'Edit customer' : 'New customer'">
    <h1 class="mb-6 text-2xl font-semibold tracking-tight">{{ $customer->exists ? 'Edit '.$customer->company_name : 'New customer' }}</h1>

    <form method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}" class="card max-w-2xl space-y-5">
        @csrf
        @if ($customer->exists) @method('PUT') @endif

        <div>
            <label for="company_name" class="form-label">Company name</label>
            <input id="company_name" name="company_name" required maxlength="255" value="{{ old('company_name', $customer->company_name) }}" class="form-input">
            <x-input-error for="company_name" />
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="primary_contact" class="form-label">Primary contact</label>
                <input id="primary_contact" name="primary_contact" maxlength="255" value="{{ old('primary_contact', $customer->primary_contact) }}" class="form-input">
                <x-input-error for="primary_contact" />
            </div>
            <div>
                <label for="email" class="form-label">Email</label>
                <input id="email" name="email" type="email" maxlength="255" value="{{ old('email', $customer->email) }}" class="form-input">
                <x-input-error for="email" />
            </div>
            <div>
                <label for="phone" class="form-label">Phone</label>
                <input id="phone" name="phone" type="tel" maxlength="50" value="{{ old('phone', $customer->phone) }}" class="form-input">
                <x-input-error for="phone" />
            </div>
            <div>
                <label for="status" class="form-label">Status</label>
                <select id="status" name="status" class="form-input">
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}" @selected(old('status', $customer->status?->value ?? 'active') === $s->value)>{{ $s->label() }}</option>
                    @endforeach
                </select>
                <x-input-error for="status" />
            </div>
        </div>
        @can('updateBilling', $customer)
            <div>
                <label for="billing_details" class="form-label">Billing details <span class="font-normal text-zinc-500">(encrypted, founder only)</span></label>
                <textarea id="billing_details" name="billing_details" rows="3" maxlength="5000" class="form-input">{{ old('billing_details', $customer->billing_details) }}</textarea>
                <x-input-error for="billing_details" />
            </div>
        @endcan

        <div class="flex justify-end gap-3">
            <a href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}" class="inline-flex items-center rounded-md px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100">Cancel</a>
            <button type="submit" class="btn-primary">{{ $customer->exists ? 'Save changes' : 'Create customer' }}</button>
        </div>
    </form>
</x-layouts.app>
