<?php

namespace Tests\Feature\Operations;

use App\Jobs\QueueHeartbeat;
use App\Models\Alert;
use App\Models\Backup;
use App\Models\User;
use App\Monitoring\Heartbeat;
use App\Monitoring\ReadinessCheck;
use App\Monitoring\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function healthOf(string $key): string
    {
        return collect(app(SystemHealth::class)->checks())->firstWhere('key', $key)['status'];
    }

    private function healthyBackup(): void
    {
        Backup::query()->create(['filename' => 'b.dump', 'driver' => 'pgsql', 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'verify_status' => 'passed', 'verified_at' => now()]);
    }

    public function test_the_heartbeat_records_the_scheduler_and_checks_the_worker(): void
    {
        config(['queue.default' => 'database']);
        $this->assertSame('failed', $this->healthOf('scheduler'));
        $this->assertSame('failed', $this->healthOf('queue'));

        Queue::fake();
        $this->artisan('company-os:heartbeat')->assertSuccessful();
        Queue::assertPushed(QueueHeartbeat::class);
        $this->assertSame('ok', $this->healthOf('scheduler'));

        (new QueueHeartbeat(now()->toIso8601String()))->handle();
        $this->assertSame('ok', $this->healthOf('queue'));

        $this->travel(30)->minutes();
        $this->assertSame('failed', $this->healthOf('scheduler'));
        $this->assertSame('failed', $this->healthOf('queue'));
    }

    public function test_a_queue_backlog_and_failed_jobs_are_flagged(): void
    {
        config(['queue.default' => 'database']);
        Heartbeat::beat(Heartbeat::QUEUE);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subHour()->timestamp, 'created_at' => now()->subHour()->timestamp]);
        DB::table('failed_jobs')->insert(['uuid' => 'x', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

        $this->assertSame('warning', $this->healthOf('queue'));
        $this->assertSame('warning', $this->healthOf('failed_jobs'));
    }

    public function test_backups_must_be_recent_and_restore_tested(): void
    {
        $this->assertSame('failed', $this->healthOf('backup'));
        $this->assertSame('failed', $this->healthOf('restore'));

        $this->healthyBackup();
        $this->assertSame('ok', $this->healthOf('backup'));
        $this->assertSame('ok', $this->healthOf('restore'));

        $this->travel(2)->days();
        $this->assertSame('failed', $this->healthOf('backup'));
        $this->travel(7)->days();
        $this->assertSame('warning', $this->healthOf('restore'));
    }

    public function test_problems_raise_one_alert_a_day(): void
    {
        config(['queue.default' => 'database']);
        $this->artisan('company-os:check-system')->assertSuccessful();
        $this->artisan('company-os:check-system')->assertSuccessful();

        $keys = Alert::query()->pluck('dedupe_key')->all();
        $this->assertContains('system:backup:'.today()->toDateString(), $keys);
        $this->assertContains('system:restore:'.today()->toDateString(), $keys);
        $this->assertContains('system:queue:'.today()->toDateString(), $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertSame(3, Alert::query()->count());

        $this->travel(1)->days();
        $this->artisan('company-os:check-system');
        $this->assertSame(6, Alert::query()->count());
    }

    public function test_the_system_page_shows_health_and_founder_only_readiness(): void
    {
        $this->healthyBackup();
        $founder = User::factory()->founder()->create();

        $this->actingAs($founder)->get('/system')->assertOk()->assertSee('Scheduler')->assertSee('b.dump')->assertSee('Pilot readiness')->assertSee('What agents may do');
        $this->actingAs(User::factory()->operator()->create())->get('/system')->assertOk()->assertSee('Scheduler')->assertDontSee('Pilot readiness');
        $this->actingAs(User::factory()->create())->get('/system')->assertForbidden();
    }

    public function test_the_readiness_check_fails_an_unsafe_installation(): void
    {
        config(['app.debug' => true, 'session.secure' => false]);
        User::factory()->founder()->create(['email' => 'founder@example.test']);

        $this->artisan('company-os:readiness')->expectsOutputToContain('Not ready')->assertFailed();

        $items = collect(app(ReadinessCheck::class)->items())->keyBy('label');
        $this->assertSame('failed', $items['Debug mode off']['status']);
        $this->assertSame('failed', $items['Secure session cookie']['status']);
        $this->assertSame('failed', $items['Founders use two-step sign-in']['status']);
        $this->assertStringContainsString('founder@example.test', $items['Founders use two-step sign-in']['detail']);
        $this->assertSame('failed', $items['Founder two-step sign-in required']['status'], 'Switched off in the test environment.');
    }
}
