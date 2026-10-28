<?php

namespace App\Http\Requests\ServicePackages;

use App\Models\ServicePackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServicePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $package = $this->route('service_package');

        return $package instanceof ServicePackage
            ? $this->user()->can('update', $package)
            : $this->user()->can('create', ServicePackage::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', Rule::unique('service_packages', 'slug')->ignore($this->route('service_package'))],
            'description' => ['nullable', 'string', 'max:5000'],
            'scope' => ['required', 'string', 'max:10000'],
            'exclusions' => ['nullable', 'string', 'max:10000'],
            'delivery_template' => ['nullable', 'string', 'max:10000'],
            'base_price' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'currency' => ['required', 'string', Rule::in(config('company_os.currencies'))],
            'estimated_days' => ['required', 'integer', 'between:1,365'],
            'revision_limit' => ['required', 'integer', 'between:0,50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'base_price.regex' => 'Enter a non-negative price with at most two decimals, e.g. 450 or 450.00.',
        ];
    }
}
