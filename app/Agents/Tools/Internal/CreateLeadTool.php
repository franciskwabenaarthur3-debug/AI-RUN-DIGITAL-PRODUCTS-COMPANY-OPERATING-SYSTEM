<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Enums\LeadSource;
use App\Exceptions\InvalidTransition;
use App\Models\Lead;
use App\Services\LeadService;
use App\Support\Domain;

/**
 * Records a researched prospect. Agent-created leads always have source
 * "research" and must say where the information came from.
 */
class CreateLeadTool extends BaseTool
{
    public function __construct(private readonly LeadService $leads) {}

    public function name(): string
    {
        return 'create_lead';
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'website' => ['nullable', 'string', 'max:2048', 'regex:#^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(:\d+)?(/\S*)?$#i'],
            'source_reference' => ['required', 'string', 'max:2000'],
            'qualification_score' => ['nullable', 'integer', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'company_name' => ['type' => 'string', 'maxLength' => 255],
                'contact_name' => ['type' => 'string', 'maxLength' => 255],
                'email' => ['type' => 'string', 'description' => 'Only a published business address'],
                'website' => ['type' => 'string', 'description' => 'e.g. example.com'],
                'source_reference' => ['type' => 'string', 'description' => 'URL or citation where this information was found'],
                'qualification_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'notes' => ['type' => 'string', 'maxLength' => 5000],
            ],
            'required' => ['company_name', 'source_reference'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        $email = isset($input['email']) ? strtolower(trim($input['email'])) : null;
        $domain = Domain::fromUrl($input['website'] ?? null);

        $duplicate = Lead::query()
            ->where(function ($q) use ($email, $domain) {
                $q->when($email, fn ($q) => $q->orWhere('email', $email))
                    ->when($domain, fn ($q) => $q->orWhere('website_domain', $domain));
            })
            ->when(! $email && ! $domain, fn ($q) => $q->whereRaw('1 = 0'))
            ->first(['id', 'company_name']);

        if ($duplicate) {
            throw new InvalidTransition("A lead with this email or website already exists: {$duplicate->company_name} (#{$duplicate->id}).");
        }

        $lead = $this->leads->create([
            ...$input,
            'email' => $email,
            'source' => LeadSource::Research,
        ], $context->agent);

        return new ToolResult(['lead_id' => $lead->id, 'company_name' => $lead->company_name]);
    }
}
