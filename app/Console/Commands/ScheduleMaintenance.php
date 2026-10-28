<?php

namespace App\Console\Commands;

use App\Services\MaintenanceService;
use Illuminate\Console\Command;

class ScheduleMaintenance extends Command
{
    protected $signature = 'company-os:schedule-maintenance';

    protected $description = 'Create maintenance visits that are due soon from active plans';

    public function handle(MaintenanceService $maintenance): int
    {
        $this->info("Scheduled {$maintenance->scheduleDue()} maintenance visit(s).");

        return self::SUCCESS;
    }
}
