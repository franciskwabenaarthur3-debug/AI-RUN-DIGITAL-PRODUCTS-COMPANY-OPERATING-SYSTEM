<?php

namespace App\Services;

use App\Agents\OutputSchema;
use App\Agents\Runtime\AgentRuntime;
use App\Agents\Runtime\RunRequest;
use App\Agents\Runtime\RunResult;
use App\Agents\Runtime\RuntimeFailure;
use App\Agents\Runtime\RuntimeRegistry;
use App\Agents\Runtime\UsageRecord;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolGateway;
use App\Agents\Tools\ToolInvoker;
use App\Enums\AgentRunStatus;
use App\Enums\TaskStatus;
use App\Models\AgentRun;
use App\Models\CompanySetting;
use App\Models\ModelUsage;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Executes one queued task with one agent run.
 *
 * 1. Claim: lock the task, re-check every precondition (pause, agent
 *    enabled, runtime available, dependencies, budget) and move it to
 *    running with a new agent_runs row. Only one worker can claim a task.
 * 2. Execute the runtime outside any transaction.
 * 3. Finish: enforce deadline, step, token and output-schema limits in
 *    code, record usage, and move the task to its next state.
 */
class TaskRunner
{
    public const SKIPPED = 'skipped';

    public const SUCCEEDED = 'succeeded';

    public const RETRY = 'retry';

    public const FAILED = 'failed';

    public function __construct(
        private readonly RuntimeRegistry $runtimes,
        private readonly TaskService $tasks,
        private readonly AuditLogger $audit,
        private readonly ToolGateway $gateway,
        private readonly AiBudgetMonitor $budget,
    ) {}

    public function run(int $taskId): string
    {
        $claimed = $this->claim($taskId);

        if ($claimed === null) {
            return self::SKIPPED;
        }

        [$run, $runtime, $request] = $claimed;

        try {
            $result = $runtime->run($request);
        } catch (RuntimeFailure $failure) {
            return $this->fail($run, $failure->errorCode, $failure->getMessage(), $failure->retryable, $failure->usage);
        } catch (Throwable $e) {
            report($e);

            return $this->fail($run, 'runtime_exception', 'The runtime raised an unexpected error.', false, []);
        }

        return $this->complete($run, $request, $result);
    }

    /**
     * @return array{0: AgentRun, 1: AgentRuntime, 2: RunRequest}|null
     */
    private function claim(int $taskId): ?array
    {
        return DB::transaction(function () use ($taskId) {
            $task = Task::query()->lockForUpdate()->find($taskId);

            // Not queued: a duplicate delivery, or the task moved on. Do nothing.
            if (! $task || $task->status !== TaskStatus::Queued) {
                return null;
            }

            // Emergency pause: leave the task queued; resuming re-dispatches it.
            if (CompanySetting::current()->isPaused()) {
                return null;
            }

            $agent = $task->agent;

            if (! $agent) {
                $this->tasks->block($task, 'No agent is assigned to this task.');

                return null;
            }

            if (! $agent->isEnabled()) {
                $this->tasks->block($task, "Agent \"{$agent->name}\" is disabled.");

                return null;
            }

            if ($task->hasOpenDependencies()) {
                $this->tasks->block($task, 'Waiting for dependencies to complete.');

                return null;
            }

            $runtime = $this->runtimes->find($agent->runtime);

            if (! $runtime) {
                $this->tasks->block($task, "Runtime \"{$agent->runtime}\" is not available in this environment.");

                return null;
            }

            $attempt = $task->attempts + 1;
            $correlationId = Context::get('correlation_id') ?? (string) Str::uuid();
            $request = new RunRequest(
                agent: $agent,
                task: $task,
                input: $task->input ?? [],
                attempt: $attempt,
                maxSteps: $agent->max_steps,
                maxTokens: $agent->max_tokens_per_run,
                deadline: CarbonImmutable::now()->addSeconds($agent->timeout_seconds),
                correlationId: $correlationId,
            );

            if ($runtime->isPaid() && ($reason = $this->budgetBlock($runtime, $request))) {
                $this->tasks->block($task, $reason);
                $this->audit->record('agent.budget_blocked', $task, ['agent_id' => $agent->id, 'reason' => $reason], actorType: 'system');

                return null;
            }

            $run = AgentRun::query()->create([
                'task_id' => $task->id,
                'agent_id' => $agent->id,
                'attempt' => $attempt,
                'status' => AgentRunStatus::Running,
                'runtime' => $runtime->name(),
                'model_key' => $agent->model_key,
                'instructions_version' => $agent->instructions_version,
                'started_at' => now(),
                'deadline_at' => $request->deadline,
                'input_hash' => hash('sha256', json_encode([$task->title, $task->description, $task->input, $agent->instructions_version])),
                'correlation_id' => $correlationId,
            ]);

            $task->attempts = $attempt;
            $task->status = TaskStatus::Running;
            $task->save();

            $request->tools = new ToolInvoker($this->gateway, new ToolContext($agent, $run, $task));

            return [$run, $runtime, $request];
        });
    }

    private function budgetBlock(AgentRuntime $runtime, RunRequest $request): ?string
    {
        $maxCost = $runtime->maxRunCostMinor($request);
        $settings = CompanySetting::current();

        if (ModelUsage::companySpentThisMonthMinor() + $maxCost > $settings->monthly_ai_budget_minor) {
            return 'The monthly AI budget would be exceeded.';
        }

        if ($request->agent->spentThisMonthMinor() + $maxCost > $request->agent->monthly_budget_minor) {
            return "The monthly budget for agent \"{$request->agent->name}\" would be exceeded.";
        }

        return null;
    }

    private function complete(AgentRun $run, RunRequest $request, RunResult $result): string
    {
        if (now()->greaterThan($request->deadline)) {
            return $this->fail($run, 'timeout', 'The run finished after its deadline.', true, $result->usage);
        }

        if ($result->steps > $request->maxSteps) {
            return $this->fail($run, 'step_limit_exceeded', "The run used {$result->steps} steps; the limit is {$request->maxSteps}.", false, $result->usage);
        }

        if ($result->totalTokens() > $request->maxTokens) {
            return $this->fail($run, 'usage_limit_exceeded', "The run used {$result->totalTokens()} tokens; the limit is {$request->maxTokens}.", false, $result->usage);
        }

        try {
            $output = OutputSchema::validate($request->agent->role, $result->output);
        } catch (ValidationException $e) {
            return $this->fail($run, 'invalid_output', 'Output failed validation: '.implode(' ', $e->validator->errors()->all()), false, $result->usage);
        }

        $completed = false;

        $outcome = DB::transaction(function () use ($run, $request, $result, $output, &$completed) {
            [$run, $task] = $this->lockForFinish($run);
            $this->recordUsage($run, $result->usage);

            if (! $task) {
                return self::SKIPPED;
            }

            $run->forceFill([
                'status' => AgentRunStatus::Succeeded,
                'finished_at' => now(),
                'output' => $output,
                'usage_metadata' => ['steps' => $result->steps, 'total_tokens' => $result->totalTokens()] + $result->metadata,
            ])->save();

            $task->result = $output;
            $task->failure_reason = null;
            if ($request->agent->review_required) {
                $task->status = TaskStatus::AwaitingReview;
            } else {
                $task->status = TaskStatus::Completed;
                $task->completed_at = now();
                $completed = true;
            }
            $task->save();

            return self::SUCCEEDED;
        });

        if ($completed) {
            $this->tasks->releaseDependents($run->task()->first());
        }

        return $outcome;
    }

    /**
     * @param  list<UsageRecord>  $usage
     */
    private function fail(AgentRun $run, string $code, string $message, bool $retryable, array $usage): string
    {
        return DB::transaction(function () use ($run, $code, $message, $retryable, $usage) {
            [$run, $task] = $this->lockForFinish($run);
            $this->recordUsage($run, $usage);

            if (! $task) {
                return self::SKIPPED;
            }

            $run->forceFill([
                'status' => AgentRunStatus::Failed,
                'finished_at' => now(),
                'error_code' => $code,
                'error_message' => Str::limit($message, 2000),
            ])->save();

            $agent = $task->agent;

            if ($retryable && $agent && $task->auto_retries < $agent->max_retries) {
                $task->auto_retries++;
                $task->status = TaskStatus::Queued;
                $task->failure_reason = "Attempt {$run->attempt} failed ({$code}); retrying.";
                $task->save();

                return self::RETRY;
            }

            $task->status = TaskStatus::Failed;
            $task->failure_reason = "[{$code}] {$message}";
            $task->save();

            return self::FAILED;
        });
    }

    /**
     * Lock the run and its task. Returns a null task when this run no longer
     * owns the task (e.g. recovery already marked it lost).
     *
     * @return array{0: AgentRun, 1: Task|null}
     */
    private function lockForFinish(AgentRun $run): array
    {
        $task = Task::query()->lockForUpdate()->findOrFail($run->task_id);
        $run = AgentRun::query()->lockForUpdate()->findOrFail($run->id);

        $owns = $run->status === AgentRunStatus::Running
            && $task->status === TaskStatus::Running
            && $task->attempts === $run->attempt;

        if (! $owns && $run->status === AgentRunStatus::Running) {
            $run->forceFill(['status' => AgentRunStatus::Failed, 'finished_at' => now(), 'error_code' => 'superseded', 'error_message' => 'The task was no longer owned by this run.'])->save();
        }

        return [$run, $owns ? $task : null];
    }

    /**
     * Cost is recorded even when the run fails: the provider still charged.
     *
     * @param  list<UsageRecord>  $usage
     */
    private function recordUsage(AgentRun $run, array $usage): void
    {
        foreach ($usage as $u) {
            ModelUsage::query()->create([
                'agent_run_id' => $run->id,
                'agent_id' => $run->agent_id,
                'provider' => $u->provider,
                'model' => $u->model,
                'input_tokens' => $u->inputTokens,
                'output_tokens' => $u->outputTokens,
                'estimated_cost_minor' => $u->estimatedCostMinor,
                'currency' => $u->currency,
                'provider_request_id' => $u->providerRequestId,
                'web_search_requests' => $u->webSearchRequests,
            ]);
        }

        if ($usage !== []) {
            $this->budget->check();
        }
    }
}
