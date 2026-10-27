<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Task::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'agent_id' => ['required', 'integer', Rule::exists('agents', 'id')],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],
            'parent_task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')],
            'depends_on' => ['nullable', 'array', 'max:20'],
            'depends_on.*' => ['integer', 'distinct', Rule::exists('tasks', 'id')],
            'instructions' => ['nullable', 'string', 'max:10000'],
            'queue_now' => ['sometimes', 'boolean'],
        ];
    }
}
