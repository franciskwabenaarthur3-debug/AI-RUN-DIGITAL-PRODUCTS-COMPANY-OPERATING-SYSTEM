<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use App\Monitoring\Heartbeat;
use Illuminate\Console\Command;

class SchedulerHeartbeat extends Command
{
    protected $signature = 'company-os:heartbeat';

    protected $description = 'Record that the scheduler is running and check that a queue worker picks up jobs';

    public function handle(): int
    {
        Heartbeat::beat(Heartbeat::SCHEDULER);
        QueueHeartbeat::dispatch(now()->toIso8601String());

        return self::SUCCESS;
    }
}
