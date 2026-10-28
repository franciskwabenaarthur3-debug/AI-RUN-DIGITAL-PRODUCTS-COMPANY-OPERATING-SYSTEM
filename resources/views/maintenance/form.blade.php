@php($editing = $plan->exists)
<x-layouts.app :title="$editing ? 'Edit '.$plan->plan_name : 'New maintenance plan'">
    <h1 class="mb-1 text-2xl font-semibold tracking-tight">{{ $editing ? 'Edit '.$plan->plan_name : 'New maintenance plan' }}</h1>
    <p class="mb-6 text-sm text-zinc-600">Record a plan the client has agreed to. Only the founder sets plan prices.</p>

    @if ($errors->has('plan'))
        <div role="alert" class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first('plan') }}</div>
    @endif

    <form method="POST" action="{{ $editing ? route('maintenance.plans.update', $plan) : route('maintenance.plans.store') }}" class="card max-w-3xl space-y-5">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="grid gap-5 sm:grid-cols-2">
            @if ($editing)
                <div><p class="form-label">Customer</p><p class="text-sm">{{ $plan->customer->company_name }}</p></div>
            @else
                <div>
                    <label for="customer_id" class="form-label">Customer</label>
                    <select id="customer_id" name="customer_id" required class="form-input">
                        <option value="">Choose…</option>
                        @foreach ($customers as $c)<option value="{{ $c->id }}" @selected((int) old('customer_id', $plan->customer_id) === $c->id)>{{ $c->company_name }}</option>@endforeach
                    </select>
                    <x-input-error for="customer_id" />
                </div>
            @endif
            <div>
                <label for="project_id" class="form-label">Site (project) <span class="font-normal text-zinc-500">(for the health check)</span></label>
                <select id="project_id" name="project_id" class="form-input">
                    <option value="">None</option>
                    @foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) old('project_id', $plan->project_id) === $p->id)>{{ $p->name }}</option>@endforeach
                </select>
                <x-input-error for="project_id" />
            </div>
            <div class="sm:col-span-2">
                <label for="plan_name" class="form-label">Plan name</label>
                <input id="plan_name" name="plan_name" required maxlength="150" value="{{ old('plan_name', $plan->plan_name) }}" class="form-input">
                <x-input-error for="plan_name" />
            </div>
            <div>
                <label for="amount" class="form-label">Price</label>
                <input id="amount" name="amount" required inputmode="decimal" value="{{ old('amount', $plan->amount_minor ? App\Support\Money::fromMinor($plan->amount_minor) : '') }}" class="form-input">
                <x-input-error for="amount" />
            </div>
            <div>
                <label for="currency" class="form-label">Currency</label>
                <select id="currency" name="currency" class="form-input">
                    @foreach (config('company_os.currencies') as $c)<option value="{{ $c }}" @selected(old('currency', $plan->currency) === $c)>{{ $c }}</option>@endforeach
                </select>
            </div>
            @foreach (['billing_frequency' => 'Billed', 'service_interval' => 'Visits'] as $field => $label)
                <div>
                    <label for="{{ $field }}" class="form-label">{{ $label }}</label>
                    <select id="{{ $field }}" name="{{ $field }}" class="form-input">
                        @foreach (App\Enums\MaintenanceFrequency::cases() as $f)<option value="{{ $f->value }}" @selected(old($field, $plan->{$field} instanceof App\Enums\MaintenanceFrequency ? $plan->{$field}->value : $plan->{$field}) === $f->value)>{{ $f->label() }}</option>@endforeach
                    </select>
                </div>
            @endforeach
            <div>
                <label for="next_service_at" class="form-label">Next visit</label>
                <input id="next_service_at" name="next_service_at" type="date" value="{{ old('next_service_at', $plan->next_service_at?->toDateString()) }}" class="form-input">
                <x-input-error for="next_service_at" />
            </div>
            @unless ($editing)
                <div>
                    <label for="term_ends_at" class="form-label">Term ends</label>
                    <input id="term_ends_at" name="term_ends_at" type="date" value="{{ old('term_ends_at', $plan->term_ends_at?->toDateString()) }}" class="form-input">
                    <x-input-error for="term_ends_at" />
                </div>
            @endunless
            <div class="sm:col-span-2">
                <label for="checklist" class="form-label">Visit checklist <span class="font-normal text-zinc-500">(one check per line; new visits use it)</span></label>
                <textarea id="checklist" name="checklist" rows="7" maxlength="5000" class="form-input">{{ old('checklist', implode("\n", $plan->checklist ?? [])) }}</textarea>
                <x-input-error for="checklist" />
                <p class="mt-1 text-xs text-zinc-500">List checks, not changes. Anything that changes the live site is recorded as a finding and needs approval.</p>
            </div>
            <div class="sm:col-span-2">
                <label for="notes" class="form-label">Notes</label>
                <textarea id="notes" name="notes" rows="3" maxlength="5000" class="form-input">{{ old('notes', $plan->notes) }}</textarea>
            </div>
        </div>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary">{{ $editing ? 'Save' : 'Create plan' }}</button>
            <a href="{{ $editing ? route('maintenance.plans.show', $plan) : route('maintenance.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.app>
