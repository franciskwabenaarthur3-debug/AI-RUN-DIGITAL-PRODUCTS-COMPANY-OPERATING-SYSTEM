<?php

namespace App\Http\Requests\Leads;

use App\Enums\LeadSource;
use App\Enums\ResearchStatus;
use App\Models\Lead;
use App\Support\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create and edit a lead, including duplicate detection on email and
 * normalized website domain.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lead = $this->route('lead');

        return $lead instanceof Lead
            ? $this->user()->can('update', $lead)
            : $this->user()->can('create', Lead::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->filled('email') ? strtolower(trim((string) $this->input('email'))) : null,
            'website' => $this->filled('website') ? trim((string) $this->input('website')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+().\-\s]{5,50}$/'],
            'website' => ['nullable', 'string', 'max:2048', 'regex:#^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(:\d+)?(/\S*)?$#i'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'source_reference' => ['nullable', 'string', 'max:2000'],
            'research_status' => ['required', Rule::enum(ResearchStatus::class)],
            'qualification_score' => ['nullable', 'integer', 'between:0,100'],
            'next_follow_up_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'website.regex' => 'Enter a website such as example.com or https://example.com.',
            'phone.regex' => 'Enter a phone number using digits, spaces and + ( ) - only.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $ignore = $this->route('lead')?->id;

                if ($email = $this->input('email')) {
                    $this->flagDuplicate($validator, 'email', Lead::query()->where('email', $email), $ignore, 'email address');
                }

                if ($domain = Domain::fromUrl($this->input('website'))) {
                    $this->flagDuplicate($validator, 'website', Lead::query()->where('website_domain', $domain), $ignore, 'website');
                }
            },
        ];
    }

    private function flagDuplicate(Validator $validator, string $field, $query, ?int $ignore, string $label): void
    {
        $existing = $query->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->first(['id', 'company_name']);

        if ($existing) {
            $validator->errors()->add($field, "A lead with this {$label} already exists: {$existing->company_name} (#{$existing->id}).");
        }
    }
}
