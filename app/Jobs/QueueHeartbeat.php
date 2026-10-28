<?php

namespace App\Jobs;

use App\Monitoring\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued by the scheduler; when a worker runs it, the worker is alive. */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $queuedAt) {}

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::QUEUE, ['queued_at' => $this->queuedAt]);
    }
}
