<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Command;

class RaiseAlerts extends Command
{
    protected $signature = 'company-os:raise-alerts';

    protected $description = 'Raise alerts for overdue maintenance, renewals, failed releases and other conditions';

    public function handle(AlertService $alerts): int
    {
        $this->info("Raised {$alerts->sweep()} new alert(s).");

        return self::SUCCESS;
    }
}
