<?php

namespace App\Console\Commands;

use App\Services\OperatingReportService;
use Illuminate\Console\Command;

class GenerateReports extends Command
{
    protected $signature = 'company-os:generate-reports';

    protected $description = 'Store the daily, weekly and monthly operating reports for periods that just ended';

    public function handle(OperatingReportService $reports): int
    {
        foreach ($reports->storeDue() as $report) {
            $this->info("Stored {$report->period->value} report for {$report->label()}.");
        }

        return self::SUCCESS;
    }
}
