<x-layouts.app title="Settings">
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Company settings</h1>
        @unless ($canUpdate)
            <p class="mt-1 text-sm text-zinc-600">Only the founder can change these settings.</p>
        @endunless
    </div>

    <form method="POST" action="{{ route('settings.update') }}" class="card max-w-2xl space-y-5">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $canUpdate) class="space-y-5">
            <div>
                <label for="company_name" class="form-label">Company name</label>
                <input id="company_name" name="company_name" type="text" required maxlength="150"
                       value="{{ old('company_name', $settings->company_name) }}" class="form-input">
                @error('company_name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="default_currency" class="form-label">Default currency</label>
                    <select id="default_currency" name="default_currency" class="form-input">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}" @selected(old('default_currency', $settings->default_currency) === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                    @error('default_currency') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="timezone" class="form-label">Timezone</label>
                    <select id="timezone" name="timezone" class="form-input">
                        @foreach (DateTimeZone::listIdentifiers() as $tz)
                            <option value="{{ $tz }}" @selected(old('timezone', $settings->timezone) === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                    @error('timezone') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="monthly_ai_budget" class="form-label">Monthly AI budget ({{ config('company_os.ai.currency') }})</label>
                    <input id="monthly_ai_budget" name="monthly_ai_budget" type="text" inputmode="decimal" required
                           value="{{ old('monthly_ai_budget', $budget) }}" class="form-input" aria-describedby="budget-help">
                    <p id="budget-help" class="mt-1 text-xs text-zinc-500">Hard limit on estimated model spending. Model providers bill in {{ config('company_os.ai.currency') }}. A paid run is refused if it could take spending over this amount; 0 allows no paid runs.</p>
                    @error('monthly_ai_budget') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="ai_budget_alert_percent" class="form-label">Warn at (% of AI budget)</label>
                    <input id="ai_budget_alert_percent" name="ai_budget_alert_percent" type="number" min="1" max="100" required
                           value="{{ old('ai_budget_alert_percent', $settings->ai_budget_alert_percent) }}" class="form-input">
                    @error('ai_budget_alert_percent') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="approval_policy" class="form-label">Approval policy</label>
                <select id="approval_policy" name="approval_policy" class="form-input">
                    @foreach ($approvalPolicies as $policy)
                        <option value="{{ $policy->value }}" @selected(old('approval_policy', $settings->approval_policy->value) === $policy->value)>{{ $policy->label() }}</option>
                    @endforeach
                </select>
                @error('approval_policy') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="operating_status" class="form-label">Operating status</label>
                <select id="operating_status" name="operating_status" class="form-input" aria-describedby="status-help">
                    @foreach ($operatingStatuses as $status)
                        <option value="{{ $status->value }}" @selected(old('operating_status', $settings->operating_status->value) === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
                <p id="status-help" class="mt-1 text-xs text-zinc-500">Paused is the emergency stop. Agents and external actions will check it before starting work.</p>
                @error('operating_status') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </fieldset>

        @if ($canUpdate)
            <div class="flex justify-end">
                <button type="submit" class="btn-primary">Save settings</button>
            </div>
        @endif
    </form>
</x-layouts.app>
