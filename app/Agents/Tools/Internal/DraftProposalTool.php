<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Models\Proposal;
use App\Proposals\ProposalChecks;
use App\Services\ProposalService;
use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * Creates a draft proposal from an active package. The agent supplies
 * words only: the price comes from the package, the recipient from the
 * lead or customer record. A person edits, submits and (after the
 * founder's approval) sends it.
 */
class DraftProposalTool extends BaseTool
{
    public function __construct(private readonly ProposalService $proposals) {}

    public function name(): string
    {
        return 'draft_proposal';
    }

    public function rules(): array
    {
        return [
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id'), 'required_without:customer_id', 'prohibits:customer_id'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id'), 'required_without:lead_id'],
            'service_package_id' => ['required', 'integer', Rule::exists('service_packages', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'max:200'],
            'introduction' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lead_id' => ['type' => 'integer', 'description' => 'The lead to address; give this or customer_id.'],
                'customer_id' => ['type' => 'integer'],
                'service_package_id' => ['type' => 'integer', 'description' => 'An active service package. Its price, scope and exclusions are used unchanged.'],
                'title' => ['type' => 'string', 'maxLength' => 200],
                'introduction' => ['type' => 'string', 'maxLength' => 5000, 'description' => 'A short, factual opening paragraph. No prices or promises beyond the package scope.'],
            ],
            'required' => ['service_package_id', 'title'],
            'additionalProperties' => false,
        ];
    }

    public function resource(array $input): array
    {
        return filled($input['lead_id'] ?? null) ? ['lead', (int) $input['lead_id']] : ['customer', (int) $input['customer_id']];
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        $proposal = $this->proposals->create($input, $context->agent);

        return new ToolResult(self::summary($proposal));
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Proposal $proposal): array
    {
        return [
            'proposal_id' => $proposal->id,
            'reference' => $proposal->label(),
            'status' => $proposal->status->value,
            'total' => Money::format($proposal->amount_minor, $proposal->currency),
            'problems' => ProposalChecks::problems($proposal),
            'sent' => false,
        ];
    }
}
