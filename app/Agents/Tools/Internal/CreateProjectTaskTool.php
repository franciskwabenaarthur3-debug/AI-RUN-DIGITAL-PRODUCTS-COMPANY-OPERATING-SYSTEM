<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Enums\TaskPriority;
use App\Exceptions\InvalidTransition;
use App\Services\TaskService;
use Illuminate\Validation\Rule;

/**
 * Proposes a subtask of the task the agent is working on. The task is
 * created but not queued: a person decides whether it runs.
 */
class CreateProjectTaskTool extends BaseTool
{
    public function __construct(private readonly TaskService $tasks) {}

    public function name(): string
    {
        return 'create_project_task';
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'agent_id' => ['nullable', 'integer', Rule::exists('agents', 'id')],
        ];
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        if (! $context->task) {
            throw new InvalidTransition('Subtasks can only be created from within a task.');
        }

        $task = $this->tasks->create([
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'priority' => $input['priority'] ?? TaskPriority::Normal->value,
            'agent_id' => $input['agent_id'] ?? null,
            'parent_task_id' => $context->task->id,
        ], $context->agent);

        return new ToolResult(['task_id' => $task->id, 'status' => $task->status->value]);
    }
}
