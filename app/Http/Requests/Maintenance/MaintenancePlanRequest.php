<?php

namespace App\Http\Requests\Maintenance;

use App\Enums\MaintenanceFrequency;
use App\Models\MaintenancePlan;
use App\Services\ServicePackageService;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MaintenancePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof MaintenancePlan
            ? $this->user()->can('update', $plan)
            : $this->user()->can('create', MaintenancePlan::class);
    }

    public function rules(): array
    {
        $creating = ! $this->route('plan') instanceof MaintenancePlan;

        return [
            'customer_id' => $creating ? ['required', 'integer', Rule::exists('customers', 'id')] : ['prohibited'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'plan_name' => ['required', 'string', 'max:150'],
            'billing_frequency' => ['required', Rule::enum(MaintenanceFrequency::class)],
            'service_interval' => ['required', Rule::enum(MaintenanceFrequency::class)],
            'amount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'currency' => ['required', 'string', Rule::in(config('company_os.currencies'))],
            'checklist' => ['nullable', 'string', 'max:5000'],
            'next_service_at' => ['nullable', 'date'],
            'term_ends_at' => $creating ? ['nullable', 'date', 'after:today'] : ['prohibited'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return ['amount.regex' => 'Enter a price with at most two decimals, e.g. 450 or 450.00.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function planData(): array
    {
        $data = $this->validated();
        $data['amount_minor'] = Money::toMinor($data['amount']);
        unset($data['amount']);
        $data['checklist'] = array_values(array_map(fn ($l) => ltrim($l, "-* \t"), ServicePackageService::lines($data['checklist'] ?? ''))) ?: null;
        if (! $this->route('plan') instanceof MaintenancePlan) {
            $data['customer_id'] = (int) $data['customer_id'];
        } elseif ($data['checklist'] === null) {
            unset($data['checklist']);
        }
        $data['project_id'] = filled($data['project_id'] ?? null) ? (int) $data['project_id'] : null;

        return $data;
    }
}
