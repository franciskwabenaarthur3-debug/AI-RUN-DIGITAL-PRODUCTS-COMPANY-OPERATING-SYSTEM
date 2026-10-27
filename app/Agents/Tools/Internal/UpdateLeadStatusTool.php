<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Validation\Rule;

/**
 * Moves a lead along the workflow. Agents may only set statuses that
 * describe their own work; "contacted" and "replied" record real-world
 * events and stay with humans, as do conversion and opt-out.
 */
class UpdateLeadStatusTool extends BaseTool
{
    public const AGENT_STATUSES = [
        LeadStatus::Researched,
        LeadStatus::Qualified,
        LeadStatus::OutreachDrafted,
        LeadStatus::Disqualified,
        LeadStatus::Lost,
    ];

    public function __construct(private readonly LeadService $leads) {}

    public function name(): string
    {
        return 'update_lead_status';
    }

    public function rules(): array
    {
        return [
            'lead_id' => ['required', 'integer', Rule::exists('leads', 'id')],
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, self::AGENT_STATUSES))],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function resource(array $input): array
    {
        return ['lead', (int) $input['lead_id']];
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        $lead = Lead::query()->findOrFail($input['lead_id']);
        $from = $lead->status;
        $lead = $this->leads->changeStatus($lead, LeadStatus::from($input['status']), $context->agent, $input['reason']);

        return new ToolResult(['lead_id' => $lead->id, 'from' => $from->value, 'to' => $lead->status->value]);
    }
}
