<?php

namespace App\Console\Commands;

use App\Enums\AlertSeverity;
use App\Monitoring\SystemHealth;
use App\Services\AlertService;
use Illuminate\Console\Command;

/**
 * Raises an alert, once a day per problem, when the queue worker, failed
 * jobs, backups or restore tests need attention. A stopped scheduler cannot
 * report itself: watch /up with an external uptime monitor and check the
 * System page.
 */
class CheckSystem extends Command
{
    protected $signature = 'company-os:check-system';

    protected $description = 'Raise alerts for queue, failed job, backup and restore problems';

    public function handle(SystemHealth $health, AlertService $alerts): int
    {
        $raised = 0;
        foreach ($health->checks() as $check) {
            if ($check['status'] === 'ok' || in_array($check['key'], ['database', 'scheduler', 'alerts', 'pause'], true)) {
                continue;
            }
            $alert = $alerts->raise('system', $check['status'] === 'failed' ? AlertSeverity::Critical : AlertSeverity::Warning,
                "{$check['label']}: needs attention", $check['detail'], "system:{$check['key']}:".today()->toDateString());
            $raised += $alert->wasRecentlyCreated ? 1 : 0;
        }
        $this->info("{$raised} new alert(s).");

        return self::SUCCESS;
    }
}
