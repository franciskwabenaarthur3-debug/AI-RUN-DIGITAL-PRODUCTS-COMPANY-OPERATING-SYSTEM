<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Models\Proposal;
use Illuminate\Validation\Rule;

/** Read-only: reports what would stop a proposal being submitted or sent. */
class ValidateProposalTool extends BaseTool
{
    public function name(): string
    {
        return 'validate_proposal';
    }

    public function rules(): array
    {
        return ['proposal_id' => ['required', 'integer', Rule::exists('proposals', 'id')]];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['proposal_id' => ['type' => 'integer']],
            'required' => ['proposal_id'],
            'additionalProperties' => false,
        ];
    }

    public function resource(array $input): array
    {
        return ['proposal', (int) $input['proposal_id']];
    }

    public function rollbackPlan(array $input): string
    {
        return 'Read-only; nothing to undo.';
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        $summary = DraftProposalTool::summary(Proposal::query()->findOrFail($input['proposal_id']));

        return new ToolResult($summary + ['valid' => $summary['problems'] === []]);
    }
}
