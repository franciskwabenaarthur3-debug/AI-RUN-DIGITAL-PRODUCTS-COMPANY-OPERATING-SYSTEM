<?php

namespace App\Backups;

use App\Enums\AlertSeverity;
use App\Models\Backup;
use App\Services\AlertService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Database backups and restore tests. PostgreSQL uses pg_dump and
 * pg_restore with fixed arguments (never a shell string); a SQLite file
 * database uses VACUUM INTO. A restore test loads a backup into a
 * separate scratch database, never the live one, and compares row counts
 * with those recorded when the backup was made.
 */
class BackupService
{
    /** Tables whose row counts are recorded and compared on restore. */
    public const COUNTED_TABLES = ['users', 'customers', 'leads', 'proposals', 'projects', 'project_releases', 'maintenance_plans', 'maintenance_tasks', 'approval_requests', 'approval_decisions', 'audit_logs', 'migrations'];

    public function __construct(private readonly AuditLogger $audit, private readonly AlertService $alerts) {}

    public function create(): Backup
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $dir = rtrim(config('company_os.backups.path'), '/');
        $filename = 'company-os-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).($driver === 'pgsql' ? '.dump' : '.sqlite');
        $backup = Backup::query()->create(['filename' => $filename, 'driver' => $driver, 'status' => 'running', 'started_at' => now()]);

        try {
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException("Cannot create the backup folder {$dir}.");
            }
            $counts = $this->counts($connection->getName());
            match ($driver) {
                'pgsql' => $this->pgDump($connection->getConfig(), $backup->path()),
                'sqlite' => $this->sqliteCopy($connection->getConfig(), $backup->path()),
                default => throw new RuntimeException("Backups are not implemented for {$driver}."),
            };
            chmod($backup->path(), 0600);

            $backup->update(['status' => 'succeeded', 'size_bytes' => filesize($backup->path()), 'sha256' => hash_file('sha256', $backup->path()), 'table_counts' => $counts, 'finished_at' => now()]);
            $this->audit->record('backup.created', $backup, ['filename' => $filename, 'size_bytes' => $backup->size_bytes], actorType: 'system');
        } catch (Throwable $e) {
            $backup->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 1000), 'finished_at' => now()]);
            $this->audit->record('backup.failed', $backup, ['error' => Str::limit($e->getMessage(), 300)], actorType: 'system');
            $this->alerts->raise('backup_failed', AlertSeverity::Critical, 'Database backup failed', Str::limit($e->getMessage(), 300), "backup_failed:{$backup->id}");
        }

        return $backup->fresh();
    }

    /** Restore the backup into the scratch database and compare row counts. */
    public function verify(Backup $backup): Backup
    {
        $detail = [];
        try {
            if ($backup->status !== 'succeeded' || ! is_file($backup->path())) {
                throw new RuntimeException('The backup file is missing or the backup did not succeed.');
            }
            if (! hash_equals((string) $backup->sha256, hash_file('sha256', $backup->path()))) {
                throw new RuntimeException('The backup file has changed since it was made (checksum mismatch).');
            }

            $restored = match ($backup->driver) {
                'pgsql' => $this->pgRestore($backup),
                'sqlite' => $this->sqliteOpen($backup),
                default => throw new RuntimeException("Restore is not implemented for {$backup->driver}."),
            };

            try {
                $counts = $this->counts($restored);
            } finally {
                DB::purge($restored);
            }
            $detail = ['expected' => $backup->table_counts, 'restored' => $counts];
            $mismatch = array_keys(array_diff_assoc($backup->table_counts ?? [], $counts));
            if ($mismatch !== []) {
                throw new RuntimeException('Row counts differ after restore for: '.implode(', ', $mismatch).'.');
            }

            $backup->update(['verify_status' => 'passed', 'verify_detail' => $detail, 'verified_at' => now()]);
            $this->audit->record('backup.verified', $backup, ['filename' => $backup->filename], actorType: 'system');
        } catch (Throwable $e) {
            $backup->update(['verify_status' => 'failed', 'verify_detail' => $detail + ['error' => Str::limit($e->getMessage(), 1000)], 'verified_at' => now()]);
            $this->audit->record('backup.verify_failed', $backup, ['error' => Str::limit($e->getMessage(), 300)], actorType: 'system');
            $this->alerts->raise('backup_verify_failed', AlertSeverity::Critical, 'Backup restore test failed', Str::limit($e->getMessage(), 300), "backup_verify_failed:{$backup->id}");
        }

        return $backup->fresh();
    }

    /** @return array<string, int> */
    private function counts(string $connection): array
    {
        $schema = DB::connection($connection)->getSchemaBuilder();

        return collect(self::COUNTED_TABLES)->filter(fn ($t) => $schema->hasTable($t))
            ->mapWithKeys(fn ($t) => [$t => DB::connection($connection)->table($t)->count()])->all();
    }

    /** @param array<string, mixed> $config */
    private function pgDump(array $config, string $path): void
    {
        $result = Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])->timeout(3600)->run([
            config('company_os.backups.pg_dump', 'pg_dump'), '--format=custom', '--no-owner', '--no-privileges',
            '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'],
            '--file='.$path, '--dbname='.$config['database'],
        ]);
        if ($result->failed()) {
            throw new RuntimeException('pg_dump failed: '.Str::limit(trim($result->errorOutput()), 500));
        }
    }

    private function pgRestore(Backup $backup): string
    {
        $live = DB::connection()->getConfig();
        $target = (string) config('company_os.backups.verify_database');
        if ($target === '') {
            throw new RuntimeException('Set BACKUP_VERIFY_DATABASE to an empty scratch database to test restores.');
        }
        $host = config('company_os.backups.verify_host') ?: $live['host'];
        if ($target === $live['database'] && $host === $live['host']) {
            throw new RuntimeException('The restore test database must not be the live database.');
        }

        $config = array_merge($live, ['database' => $target, 'host' => $host]);
        $result = Process::env(['PGPASSWORD' => (string) ($config['password'] ?? '')])->timeout(3600)->run([
            config('company_os.backups.pg_restore', 'pg_restore'), '--clean', '--if-exists', '--no-owner', '--no-privileges', '--exit-on-error',
            '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'],
            '--dbname='.$target, $backup->path(),
        ]);
        if ($result->failed()) {
            throw new RuntimeException('pg_restore failed: '.Str::limit(trim($result->errorOutput()), 500));
        }

        Config::set('database.connections.backup_verify', $config);

        return 'backup_verify';
    }

    /** @param array<string, mixed> $config */
    private function sqliteCopy(array $config, string $path): void
    {
        if (($config['database'] ?? ':memory:') === ':memory:') {
            throw new RuntimeException('An in-memory database cannot be backed up.');
        }
        DB::statement('VACUUM INTO ?', [$path]);
    }

    private function sqliteOpen(Backup $backup): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'company-os-restore-');
        copy($backup->path(), $copy);
        Config::set('database.connections.backup_verify', ['driver' => 'sqlite', 'database' => $copy, 'prefix' => '', 'foreign_key_constraints' => true]);

        return 'backup_verify';
    }
}
