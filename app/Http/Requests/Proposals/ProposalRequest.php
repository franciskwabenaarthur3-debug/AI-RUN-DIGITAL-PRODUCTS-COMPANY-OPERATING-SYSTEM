<?php

namespace App\Http\Requests\Proposals;

use App\Models\Proposal;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProposalRequest extends FormRequest
{
    private const MONEY = 'regex:/^\d{1,13}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $proposal = $this->route('proposal');

        return $proposal instanceof Proposal
            ? $this->user()->can('update', $proposal)
            : $this->user()->can('create', Proposal::class);
    }

    protected function prepareForValidation(): void
    {
        // Rows left completely blank are ignored.
        $items = array_values(array_filter((array) $this->input('extra_items', []), fn ($row) => is_array($row) && collect($row)->filter(fn ($v) => filled($v))->isNotEmpty()));
        $this->merge(['extra_items' => $items]);
    }

    public function rules(): array
    {
        $creating = ! $this->route('proposal') instanceof Proposal;

        return [
            'lead_id' => $creating ? ['nullable', 'integer', Rule::exists('leads', 'id'), 'required_without:customer_id', 'prohibits:customer_id'] : ['prohibited'],
            'customer_id' => $creating ? ['nullable', 'integer', Rule::exists('customers', 'id'), 'required_without:lead_id'] : ['prohibited'],
            'project_id' => $creating ? ['nullable', 'integer', Rule::exists('projects', 'id')] : ['prohibited'],
            'service_package_id' => $creating ? ['required', 'integer', Rule::exists('service_packages', 'id')->where('is_active', true)] : ['prohibited'],
            'title' => ['required', 'string', 'max:200'],
            'recipient_name' => ['nullable', 'string', 'max:150'],
            'recipient_email' => ['nullable', 'email:rfc', 'max:255'],
            'introduction' => ['nullable', 'string', 'max:5000'],
            'scope' => [$creating ? 'nullable' : 'required', 'string', 'max:10000'],
            'exclusions' => ['nullable', 'string', 'max:10000'],
            'extra_items' => ['array', 'max:'.((int) config('company_os.proposals.max_items') - 1)],
            'extra_items.*.description' => ['required', 'string', 'max:300'],
            'extra_items.*.quantity' => ['required', 'integer', 'between:1,1000'],
            'extra_items.*.unit_price' => ['required', 'string', self::MONEY],
            'discount_type' => ['required', Rule::in(['none', 'amount', 'percent'])],
            'discount_value' => ['nullable', 'required_unless:discount_type,none', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'discount_reason' => ['nullable', 'string', 'max:500'],
            'payment_terms' => [$creating ? 'nullable' : 'required', 'string', 'max:2000'],
            'expires_at' => [$creating ? 'nullable' : 'required', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'extra_items.*.unit_price.regex' => 'Enter a non-negative price with at most two decimals.',
            'discount_value.regex' => 'Enter a non-negative number with at most two decimals.',
            'lead_id.required_without' => 'Choose a lead or a customer.',
            'lead_id.prohibits' => 'Choose a lead or a customer, not both.',
            'service_package_id.exists' => 'Choose an active service package.',
        ];
    }

    /**
     * The validated input in the shape ProposalService expects.
     *
     * @return array<string, mixed>
     */
    public function proposalData(): array
    {
        $data = $this->validated();
        $lines = fn (?string $text) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text)), fn ($l) => $l !== ''));

        $type = $data['discount_type'];
        $value = match ($type) {
            'amount' => Money::toMinor($data['discount_value']),
            // Percentages are whole numbers; "10.5" is refused by the calculator as 10.5 != int.
            'percent' => str_contains($data['discount_value'], '.') ? -1 : (int) $data['discount_value'],
            default => 0,
        };

        $out = [
            'title' => $data['title'],
            'recipient_name' => $data['recipient_name'] ?? null,
            'recipient_email' => $data['recipient_email'] ?? null,
            'introduction' => $data['introduction'] ?? null,
            'extra_items' => array_map(fn ($row) => [
                'description' => $row['description'],
                'quantity' => (int) $row['quantity'],
                'unit_price_minor' => Money::toMinor($row['unit_price']),
            ], $data['extra_items'] ?? []),
            'discount_type' => $type,
            'discount_value' => $value,
            'discount_reason' => $data['discount_reason'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ];

        if (filled($data['scope'] ?? null)) {
            $out['scope'] = $lines($data['scope']);
            $out['exclusions'] = $lines($data['exclusions'] ?? '');
        }

        foreach (['lead_id', 'customer_id', 'project_id', 'service_package_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }

        return $out;
    }
}
