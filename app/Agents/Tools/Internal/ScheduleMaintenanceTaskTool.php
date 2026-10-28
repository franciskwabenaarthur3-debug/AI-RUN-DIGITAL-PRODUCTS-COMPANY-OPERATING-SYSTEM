<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Models\MaintenancePlan;
use App\Services\MaintenanceService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Schedules an extra maintenance visit on an active plan. The visit is
 * only scheduled: people carry it out, and changes to a live site still
 * need the founder's approval.
 */
class ScheduleMaintenanceTaskTool extends BaseTool
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function name(): string
    {
        return 'schedule_maintenance_task';
    }

    public function rules(): array
    {
        return [
            'maintenance_plan_id' => ['required', 'integer', Rule::exists('maintenance_plans', 'id')],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:'.today()->addYear()->toDateString()],
            'title' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'maintenance_plan_id' => ['type' => 'integer'],
                'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, today or later, within a year'],
                'title' => ['type' => 'string', 'maxLength' => 200],
            ],
            'required' => ['maintenance_plan_id', 'date'],
            'additionalProperties' => false,
        ];
    }

    public function resource(array $input): array
    {
        return ['maintenance_plan', (int) $input['maintenance_plan_id']];
    }

    public function rollbackPlan(array $input): string
    {
        return 'Cancel the scheduled visit in Company OS.';
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        $task = $this->maintenance->scheduleTask(MaintenancePlan::query()->findOrFail($input['maintenance_plan_id']), Carbon::parse($input['date']), $context->agent, $input['title'] ?? null);

        return new ToolResult(['maintenance_task_id' => $task->id, 'scheduled_at' => $task->scheduled_at->toDateString(), 'status' => $task->status->value]);
    }
}
