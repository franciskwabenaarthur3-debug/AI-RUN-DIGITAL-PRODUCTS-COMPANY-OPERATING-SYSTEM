<?php

namespace App\Console\Commands;

use App\Enums\AgentRunStatus;
use App\Enums\TaskStatus;
use App\Jobs\RunAgentTask;
use App\Models\AgentRun;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds runs whose worker died (still "running" well past the deadline),
 * marks them lost and retries or fails their tasks within the agent's
 * retry limit. Scheduled every five minutes.
 */
class RecoverStaleRuns extends Command
{
    protected $signature = 'company-os:recover-stale-runs';

    protected $description = 'Mark agent runs lost by crashed workers as failed and retry their tasks';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(config('company_os.agents.stale_run_grace_seconds'));
        $recovered = 0;

        AgentRun::query()
            ->where('status', AgentRunStatus::Running)
            ->where('deadline_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (int $runId) use (&$recovered) {
                $requeue = DB::transaction(function () use ($runId, &$recovered) {
                    $run = AgentRun::query()->lockForUpdate()->find($runId);
                    if (! $run || $run->status !== AgentRunStatus::Running) {
                        return null;
                    }

                    $task = Task::query()->lockForUpdate()->find($run->task_id);

                    $run->forceFill([
                        'status' => AgentRunStatus::Failed,
                        'finished_at' => now(),
                        'error_code' => 'worker_lost',
                        'error_message' => 'The worker stopped before finishing this run.',
                    ])->save();
                    $recovered++;

                    if (! $task || $task->status !== TaskStatus::Running || $task->attempts !== $run->attempt) {
                        return null;
                    }

                    if ($task->agent && $task->auto_retries < $task->agent->max_retries) {
                        $task->auto_retries++;
                        $task->status = TaskStatus::Queued;
                        $task->failure_reason = "Attempt {$run->attempt} was lost (worker stopped); retrying.";
                        $task->save();

                        return $task->id;
                    }

                    $task->status = TaskStatus::Failed;
                    $task->failure_reason = '[worker_lost] The worker stopped before finishing and no retries remain.';
                    $task->save();

                    return null;
                });

                if ($requeue) {
                    RunAgentTask::dispatch($requeue);
                }
            });

        $this->info("Recovered {$recovered} stale run(s).");

        return self::SUCCESS;
    }
}
