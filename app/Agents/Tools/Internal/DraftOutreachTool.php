<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Validation\Rule;

/**
 * Saves an outreach message as a draft on the lead. It is never sent:
 * Company OS has no outbound channel, and sending stays with a person.
 */
class DraftOutreachTool extends BaseTool
{
    public const CHANNELS = ['email', 'phone', 'other'];

    public function __construct(private readonly LeadService $leads) {}

    public function name(): string
    {
        return 'draft_outreach';
    }

    public function rules(): array
    {
        return [
            'lead_id' => ['required', 'integer', Rule::exists('leads', 'id')],
            'channel' => ['required', Rule::in(self::CHANNELS)],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lead_id' => ['type' => 'integer'],
                'channel' => ['type' => 'string', 'enum' => self::CHANNELS],
                'subject' => ['type' => 'string', 'maxLength' => 200],
                'body' => ['type' => 'string', 'maxLength' => 5000],
            ],
            'required' => ['lead_id', 'channel', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function resource(array $input): array
    {
        return ['lead', (int) $input['lead_id']];
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        $lead = Lead::query()->findOrFail($input['lead_id']);
        $activity = $this->leads->recordOutreachDraft($lead, $input['channel'], $input['subject'] ?? null, $input['body'], $context->agent);

        return new ToolResult(['lead_id' => $lead->id, 'activity_id' => $activity->id, 'sent' => false]);
    }
}
