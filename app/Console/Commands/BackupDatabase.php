<?php

namespace App\Console\Commands;

use App\Backups\BackupService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'company-os:backup';

    protected $description = 'Back up the database to the private backup folder';

    public function handle(BackupService $backups): int
    {
        $backup = $backups->create();
        if ($backup->status !== 'succeeded') {
            $this->error("Backup failed: {$backup->error}");

            return self::FAILURE;
        }

        $this->info("Backup {$backup->filename} ({$backup->size_bytes} bytes, sha256 {$backup->sha256}). Copy it off this server.");

        return self::SUCCESS;
    }
}
