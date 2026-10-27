<?php

namespace App\Http\Controllers;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\InvalidTransition;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Models\Agent;
use App\Models\CompanySetting;
use App\Models\Task;
use App\Services\TaskService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Task::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'agent' => ['nullable', 'integer'],
        ]);

        $tasks = Task::query()
            ->with('agent:id,name')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $q->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$like]);
            })
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($filters['agent'] ?? null, fn ($q, $a) => $q->where('agent_id', $a))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('tasks.index', [
            'tasks' => $tasks,
            'filters' => $filters,
            'statuses' => TaskStatus::cases(),
            'priorities' => TaskPriority::cases(),
            'agents' => Agent::query()->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Task::class);

        return view('tasks.create', [
            'agents' => Agent::query()->orderBy('id')->get(),
            'priorities' => TaskPriority::cases(),
            'openTasks' => Task::query()->whereNotIn('status', [TaskStatus::Cancelled])->latest('id')->limit(100)->get(['id', 'title', 'status']),
            'selectedAgent' => $request->integer('agent') ?: null,
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $input = filled($data['instructions'] ?? null) ? ['instructions' => $data['instructions']] : null;

        $task = $this->tasks->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'agent_id' => $data['agent_id'],
            'priority' => $data['priority'],
            'due_at' => $data['due_at'] ?? null,
            'parent_task_id' => $data['parent_task_id'] ?? null,
            'input' => $input,
        ], $request->user(), $data['depends_on'] ?? []);

        if ($request->boolean('queue_now')) {
            try {
                $this->tasks->queue($task, $request->user());
            } catch (InvalidTransition $e) {
                return redirect()->route('tasks.show', $task)->withErrors(['task' => 'Task created but not queued: '.$e->getMessage()]);
            }
        }

        return redirect()->route('tasks.show', $task)->with('status', 'Task created.');
    }

    public function show(Task $task): View
    {
        $this->authorize('view', $task);

        $currency = CompanySetting::current()->default_currency;
        $runs = $task->runs()->with('usage')->get();
        $estimated = $runs->flatMap->usage->sum('estimated_cost_minor');

        return view('tasks.show', [
            'task' => $task->load(['agent', 'parent:id,title', 'dependencies:id,title,status', 'dependents:id,title,status', 'children:id,title,status,parent_task_id']),
            'runs' => $runs,
            'cost' => Money::format($estimated, $currency),
            'tokens' => $runs->flatMap->usage->sum(fn ($u) => $u->input_tokens + $u->output_tokens),
        ]);
    }

    public function queue(Request $request, Task $task): RedirectResponse
    {
        return $this->act($request, $task, fn () => $this->tasks->queue($task, $request->user()), 'Task queued.');
    }

    public function retry(Request $request, Task $task): RedirectResponse
    {
        return $this->act($request, $task, fn () => $this->tasks->retry($task, $request->user()), 'Task queued for another attempt.');
    }

    public function cancel(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['accepted']]);

        return $this->act($request, $task, fn () => $this->tasks->cancel($task, $request->user(), $data['reason'] ?? null), 'Task cancelled.');
    }

    public function accept(Request $request, Task $task): RedirectResponse
    {
        return $this->act($request, $task, fn () => $this->tasks->acceptReview($task, $request->user()), 'Result accepted.');
    }

    public function reject(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act($request, $task, fn () => $this->tasks->rejectReview($task, $request->user(), $data['reason']), 'Result rejected.');
    }

    private function act(Request $request, Task $task, callable $action, string $message): RedirectResponse
    {
        $this->authorize('operate', $task);

        try {
            $action();
        } catch (InvalidTransition $e) {
            return back()->withErrors(['task' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }
}
