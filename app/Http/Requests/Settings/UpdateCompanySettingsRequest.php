<?php

namespace App\Http\Requests\Settings;

use App\Enums\ApprovalPolicy;
use App\Enums\OperatingStatus;
use App\Models\CompanySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', CompanySetting::current());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:150'],
            'default_currency' => ['required', 'string', Rule::in(config('company_os.currencies'))],
            'timezone' => ['required', 'timezone:all'],
            'approval_policy' => ['required', Rule::enum(ApprovalPolicy::class)],
            'monthly_ai_budget' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'ai_budget_alert_percent' => ['sometimes', 'integer', 'between:1,100'],
            'operating_status' => ['required', Rule::enum(OperatingStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monthly_ai_budget.regex' => 'Enter a non-negative amount with at most two decimals, e.g. 25 or 25.50.',
        ];
    }
}
