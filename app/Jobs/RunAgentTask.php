<?php

namespace App\Jobs;

use App\Models\Task;
use App\Services\TaskRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue wrapper around TaskRunner. Retries are decided by the runner and
 * scheduled as new jobs, so the queue's own retry is disabled; duplicate
 * deliveries are harmless because the runner claims tasks atomically.
 */
class RunAgentTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Hard ceiling for a worker; per-agent deadlines are shorter. */
    public int $timeout = 900;

    public function __construct(public readonly int $taskId) {}

    public function handle(TaskRunner $runner): void
    {
        if ($runner->run($this->taskId) === TaskRunner::RETRY) {
            $retries = (int) Task::query()->whereKey($this->taskId)->value('auto_retries');
            $delay = config('company_os.agents.retry_backoff_seconds') * (2 ** max(0, $retries - 1));

            self::dispatch($this->taskId)->delay(now()->addSeconds($delay));
        }
    }
}
