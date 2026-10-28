<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Enums\RiskLevel;
use App\Governance\Payload;
use App\Services\ApprovalService;
use Illuminate\Validation\Rule;

/**
 * Asks the founder to decide on something the agent cannot do itself,
 * such as sending a drafted message. Approving records the decision only:
 * nothing in Company OS executes these actions yet, so a person carries
 * them out.
 */
class RequestApprovalTool extends BaseTool
{
    public const ACTIONS = [
        'send_outreach' => 'Send a drafted outreach message',
        'send_proposal' => 'Send a proposal to a customer',
        'publish_release' => 'Publish a release',
        'other' => 'Another action described in the summary',
    ];

    public function __construct(private readonly ApprovalService $approvals) {}

    public function name(): string
    {
        return 'request_approval';
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(array_keys(self::ACTIONS))],
            'summary' => ['required', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000'],
            'risk_level' => ['required', Rule::enum(RiskLevel::class)],
            'rollback_plan' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:20'],
            'evidence.*' => ['string', 'max:1000'],
            'lead_id' => ['nullable', 'integer', Rule::exists('leads', 'id')],
        ];
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        $resourceId = isset($input['lead_id']) ? (int) $input['lead_id'] : null;
        $payload = ['summary' => $input['summary']];

        $request = $this->approvals->request(
            actionType: 'agent.'.$input['action'],
            payload: $payload,
            reason: $input['reason'],
            risk: RiskLevel::from($input['risk_level']),
            rollbackPlan: $input['rollback_plan'],
            idempotencyKey: 'agent:'.$context->agent->id.':'.($context->run?->id ?? 'none').':'.hash('sha256', Payload::canonical($input)),
            requester: $context->agent,
            resourceType: $resourceId ? 'lead' : null,
            resourceId: $resourceId,
            task: $context->task,
            evidence: $input['evidence'] ?? [],
        );

        return new ToolResult(['approval_request_id' => $request->id, 'status' => $request->status->value]);
    }
}
