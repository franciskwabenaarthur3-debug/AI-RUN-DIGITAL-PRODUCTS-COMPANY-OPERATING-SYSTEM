<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Exceptions\InvalidTransition;
use App\Jobs\RunAgentTask;
use App\Models\Agent;
use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Human- and system-initiated task lifecycle changes. Every change goes
 * through transition(), which locks the task and refuses anything the
 * TaskStatus state machine does not allow.
 */
class TaskService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $dependsOn
     */
    public function create(array $attributes, User|Agent $actor, array $dependsOn = []): Task
    {
        return DB::transaction(function () use ($attributes, $actor, $dependsOn) {
            $task = new Task($attributes);
            $task->created_by_type = $actor instanceof Agent ? 'agent' : 'user';
            $task->created_by_id = $actor->id;
            $task->save();

            if ($dependsOn !== []) {
                $task->dependencies()->sync(array_values(array_unique($dependsOn)));
            }

            $this->audit->record('task.created', $task, ['agent_id' => $task->agent_id], $actor);

            return $task;
        });
    }

    public function queue(Task $task, User $actor): Task
    {
        $agent = $task->agent;

        if (! $agent) {
            throw new InvalidTransition('Assign an agent before queueing this task.');
        }

        if (! $agent->isEnabled()) {
            throw new InvalidTransition("Agent \"{$agent->name}\" is disabled and cannot start new work.");
        }

        return $this->enqueue($task, $actor, 'task.queued');
    }

    public function retry(Task $task, User $actor): Task
    {
        if (! in_array($task->status, [TaskStatus::Failed, TaskStatus::Blocked], true)) {
            throw new InvalidTransition('Only failed or blocked tasks can be retried.');
        }

        if (! $task->agent?->isEnabled()) {
            throw new InvalidTransition('The assigned agent is disabled.');
        }

        return $this->enqueue($task, $actor, 'task.retried', fn (Task $t) => $t->auto_retries = 0);
    }

    public function cancel(Task $task, User $actor, ?string $reason = null): Task
    {
        return $this->transition($task, TaskStatus::Cancelled, function (Task $t) use ($reason) {
            $t->failure_reason = $reason ? "Cancelled: {$reason}" : 'Cancelled.';
        }, 'task.cancelled', $actor);
    }

    public function acceptReview(Task $task, User $actor): Task
    {
        if ($task->status !== TaskStatus::AwaitingReview) {
            throw new InvalidTransition('This task is not awaiting review.');
        }

        $task = $this->transition($task, TaskStatus::Completed, fn (Task $t) => $t->completed_at = now(), 'task.review_accepted', $actor);
        $this->releaseDependents($task);

        return $task;
    }

    public function rejectReview(Task $task, User $actor, string $reason): Task
    {
        if ($task->status !== TaskStatus::AwaitingReview) {
            throw new InvalidTransition('This task is not awaiting review.');
        }

        return $this->transition($task, TaskStatus::Failed, fn (Task $t) => $t->failure_reason = "Rejected in review: {$reason}", 'task.review_rejected', $actor);
    }

    /**
     * Called by the runner when a precondition is not met.
     */
    public function block(Task $task, string $reason): Task
    {
        return $this->transition($task, TaskStatus::Blocked, fn (Task $t) => $t->failure_reason = $reason);
    }

    /**
     * Queue blocked dependents whose dependencies are now all complete.
     */
    public function releaseDependents(Task $task): void
    {
        foreach ($task->dependents()->where('status', TaskStatus::Blocked)->get() as $dependent) {
            if (! $dependent->hasOpenDependencies() && $dependent->agent?->isEnabled()) {
                $this->enqueue($dependent, null, 'task.dependencies_met');
            }
        }
    }

    /**
     * Re-dispatch queued tasks, e.g. after an emergency pause is lifted.
     */
    public function dispatchQueued(): int
    {
        $ids = Task::query()->where('status', TaskStatus::Queued)->pluck('id');
        $ids->each(fn (int $id) => RunAgentTask::dispatch($id)->afterCommit());

        return $ids->count();
    }

    private function enqueue(Task $task, ?User $actor, string $action, ?Closure $mutate = null): Task
    {
        return DB::transaction(function () use ($task, $actor, $action, $mutate) {
            if ($task->hasOpenDependencies()) {
                return $this->transition($task, TaskStatus::Blocked, fn (Task $t) => $t->failure_reason = 'Waiting for dependencies to complete.', $action, $actor);
            }

            $task = $this->transition($task, TaskStatus::Queued, function (Task $t) use ($mutate) {
                $t->queued_at = now();
                $t->failure_reason = null;
                $mutate && $mutate($t);
            }, $action, $actor);

            RunAgentTask::dispatch($task->id)->afterCommit();

            return $task;
        });
    }

    private function transition(Task $task, TaskStatus $target, ?Closure $mutate = null, ?string $auditAction = null, ?User $actor = null): Task
    {
        return DB::transaction(function () use ($task, $target, $mutate, $auditAction, $actor) {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (! $task->status->canTransitionTo($target)) {
                throw new InvalidTransition("A task cannot move from {$task->status->label()} to {$target->label()}.");
            }

            $from = $task->status;
            $task->status = $target;
            $mutate && $mutate($task);
            $task->save();

            if ($auditAction) {
                $this->audit->record($auditAction, $task, ['from' => $from->value, 'to' => $target->value], $actor, $actor ? 'user' : 'system');
            }

            return $task;
        });
    }
}
