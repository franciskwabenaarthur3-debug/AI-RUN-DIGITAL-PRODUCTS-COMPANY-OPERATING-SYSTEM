<?php

namespace App\Console\Commands;

use App\Backups\BackupService;
use App\Models\Backup;
use Illuminate\Console\Command;

class VerifyBackup extends Command
{
    protected $signature = 'company-os:verify-backup {--id= : Backup id (default: the latest successful one)}';

    protected $description = 'Restore a backup into the scratch database and compare row counts';

    public function handle(BackupService $backups): int
    {
        $backup = $this->option('id')
            ? Backup::query()->find($this->option('id'))
            : Backup::query()->where('status', 'succeeded')->latest('id')->first();
        if (! $backup) {
            $this->error('No backup to test.');

            return self::FAILURE;
        }

        $backup = $backups->verify($backup);
        if ($backup->verify_status !== 'passed') {
            $this->error("Restore test failed: {$backup->verify_detail['error']}");

            return self::FAILURE;
        }

        foreach ($backup->verify_detail['restored'] as $table => $count) {
            $this->line("{$table}: {$count}");
        }
        $this->info("Restore test passed for {$backup->filename}.");

        return self::SUCCESS;
    }
}
