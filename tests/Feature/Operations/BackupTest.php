<?php

namespace Tests\Feature\Operations;

use App\Backups\BackupService;
use App\Models\Alert;
use App\Models\Backup;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/company-os-backup-test-'.uniqid();
        config(['company_os.backups.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function pgToolsMatchServer(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }
        $client = @shell_exec('pg_dump --version 2>/dev/null');
        $server = DB::selectOne('show server_version')->server_version ?? '';

        return $client && preg_match('/(\d+)\./', $client, $c) && preg_match('/^(\d+)/', $server, $s) && (int) $c[1] >= (int) $s[1];
    }

    public function test_a_failed_backup_is_recorded_and_raises_a_critical_alert(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            config(['company_os.backups.pg_dump' => '/nonexistent/pg_dump']);
        }

        $this->artisan('company-os:backup')->assertFailed();

        $backup = Backup::query()->sole();
        $this->assertSame('failed', $backup->status);
        $this->assertNotEmpty($backup->error);
        $this->assertSame('critical', Alert::query()->where('dedupe_key', "backup_failed:{$backup->id}")->sole()->severity->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.failed']);
    }

    public function test_a_failed_backup_cannot_be_restore_tested(): void
    {
        $backup = Backup::query()->create(['filename' => 'missing.dump', 'driver' => 'pgsql', 'status' => 'failed', 'started_at' => now()]);

        $this->assertSame('failed', app(BackupService::class)->verify($backup)->verify_status);
        $this->assertSame(1, Alert::query()->where('type', 'backup_verify_failed')->count());
    }

    public function test_postgres_backup_restores_into_the_scratch_database_with_the_same_rows(): void
    {
        if (! $this->pgToolsMatchServer()) {
            $this->markTestSkipped('Needs PostgreSQL with pg_dump of the same or newer major version.');
        }

        // pg_dump cannot see the test transaction, so back up a separate, committed database.
        $base = DB::connection()->getDatabaseName();
        config(['database.connections.pg_admin' => config('database.connections.pgsql')]);
        foreach ([$base.'_backup_src', $base.'_restore'] as $name) {
            if (! DB::connection('pg_admin')->selectOne('select 1 as found from pg_database where datname = ?', [$name])) {
                DB::connection('pg_admin')->statement("CREATE DATABASE \"{$name}\"");
            }
        }
        DB::purge('pg_admin');
        config(['database.connections.backup_src' => array_merge(config('database.connections.pgsql'), ['database' => $base.'_backup_src'])]);
        $this->artisan('migrate:fresh', ['--database' => 'backup_src', '--force' => true]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('backup_src');

        try {
            Lead::factory()->count(3)->create();
            $backup = app(BackupService::class)->create();
            $this->assertSame('succeeded', $backup->status, (string) $backup->error);
            $this->assertSame(3, $backup->table_counts['leads']);
            $this->assertSame('0600', substr(sprintf('%o', fileperms($backup->path())), -4));

            // The live database is never a restore target.
            config(['company_os.backups.verify_database' => $base.'_backup_src']);
            $this->assertStringContainsString('must not be the live database', app(BackupService::class)->verify($backup)->verify_detail['error']);

            config(['company_os.backups.verify_database' => $base.'_restore']);
            $verified = app(BackupService::class)->verify($backup->fresh());
            $this->assertSame('passed', $verified->verify_status, json_encode($verified->verify_detail));
            $this->assertSame(3, $verified->verify_detail['restored']['leads']);

            // A changed file is refused.
            file_put_contents($backup->path(), 'x', FILE_APPEND);
            $this->assertStringContainsString('checksum', app(BackupService::class)->verify($backup->fresh())->verify_detail['error']);
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('backup_src');
            DB::purge('backup_verify');
        }
    }
}
