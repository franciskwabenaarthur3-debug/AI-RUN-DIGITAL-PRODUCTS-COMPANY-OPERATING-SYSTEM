<?php

namespace App\Monitoring;

use App\Models\Alert;
use App\Models\Backup;
use App\Models\CompanySetting;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Whether the parts Company OS needs are working: database, scheduler,
 * queue worker, backups. Each check is ok, warning or failed.
 */
class SystemHealth
{
    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        $checks = [];
        $add = function (string $key, string $label, string $status, string $detail) use (&$checks) {
            $checks[] = compact('key', 'label', 'status', 'detail');
        };

        try {
            DB::select('select 1');
            $add('database', 'Database', 'ok', DB::connection()->getDriverName().' connection works');
        } catch (Throwable $e) {
            $add('database', 'Database', 'failed', 'Cannot connect: '.class_basename($e));

            return $checks;
        }

        $cfg = config('company_os.monitoring');
        $scheduler = Heartbeat::lastSeen(Heartbeat::SCHEDULER);
        $add('scheduler', 'Scheduler', match (true) {
            ! $scheduler => 'failed',
            $scheduler->lt(now()->subMinutes($cfg['scheduler_stale_minutes'])) => 'failed',
            default => 'ok',
        }, $scheduler ? 'Last ran '.$scheduler->diffForHumans() : 'Has never run. Set up the cron entry for schedule:run.');

        $queue = Heartbeat::lastSeen(Heartbeat::QUEUE);
        $oldest = DB::table('jobs')->min('created_at');
        $backlog = $oldest ? now()->timestamp - (int) $oldest : 0;
        $add('queue', 'Queue worker', match (true) {
            config('queue.default') === 'sync' => 'warning',
            ! $queue || $queue->lt(now()->subMinutes($cfg['queue_stale_minutes'])) => 'failed',
            $backlog > $cfg['queue_backlog_minutes'] * 60 => 'warning',
            default => 'ok',
        }, config('queue.default') === 'sync'
            ? 'The queue runs inside web requests (QUEUE_CONNECTION=sync); use the database queue with a worker.'
            : ($queue ? 'Last job processed '.$queue->diffForHumans() : 'No worker has processed a job yet. Start queue:work.').($backlog > 0 ? ' · oldest waiting job '.intdiv($backlog, 60).' min' : ''));

        $failedJobs = DB::table('failed_jobs')->count();
        $add('failed_jobs', 'Failed queue jobs', $failedJobs > 0 ? 'warning' : 'ok', $failedJobs > 0 ? "{$failedJobs} failed job(s); see queue:failed" : 'None');

        $last = Backup::query()->where('status', 'succeeded')->latest('id')->first();
        $failedBackup = Backup::query()->latest('id')->first();
        $add('backup', 'Backups', match (true) {
            ! $last => 'failed',
            $last->finished_at->lt(now()->subHours(config('company_os.backups.max_age_hours'))) => 'failed',
            $failedBackup?->status === 'failed' => 'warning',
            default => 'ok',
        }, $last ? "Last backup {$last->finished_at->diffForHumans()} ({$last->filename})".($failedBackup?->status === 'failed' ? ' · the latest attempt failed' : '') : 'No successful backup yet. Run company-os:backup.');

        $verified = Backup::query()->where('verify_status', 'passed')->latest('verified_at')->first();
        $add('restore', 'Restore test', match (true) {
            ! $verified => 'failed',
            $verified->verified_at->lt(now()->subDays(config('company_os.backups.verify_max_age_days'))) => 'warning',
            default => 'ok',
        }, $verified ? "Last passed {$verified->verified_at->diffForHumans()}" : 'No backup has been restored and checked yet. Run company-os:verify-backup.');

        $critical = Alert::query()->open()->where('severity', 'critical')->count();
        $add('alerts', 'Critical alerts', $critical > 0 ? 'warning' : 'ok', $critical > 0 ? "{$critical} not acknowledged" : 'None open');

        $settings = CompanySetting::current();
        $add('pause', 'Operations', $settings->isPaused() ? 'warning' : 'ok', $settings->isPaused() ? 'Paused: agents and approved actions are held' : 'Running');

        return $checks;
    }

    public function overall(): string
    {
        $statuses = array_column($this->checks(), 'status');

        return in_array('failed', $statuses, true) ? 'failed' : (in_array('warning', $statuses, true) ? 'warning' : 'ok');
    }
}
