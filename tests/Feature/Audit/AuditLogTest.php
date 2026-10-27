<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_founder_can_view_audit_log(): void
    {
        $this->actingAs(User::factory()->operator()->create())->get('/audit-log')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/audit-log')->assertForbidden();
        $this->actingAs(User::factory()->founder()->create())->get('/audit-log')->assertOk();
    }

    public function test_audit_log_link_is_hidden_from_non_founders(): void
    {
        $this->actingAs(User::factory()->operator()->create())->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Audit log');
    }

    public function test_audit_log_lists_and_filters_events(): void
    {
        $founder = User::factory()->founder()->create();
        $logger = app(AuditLogger::class);
        $logger->record('test.alpha', actor: $founder);
        $logger->record('test.beta', actor: $founder);

        $this->actingAs($founder)->get('/audit-log?action=test.alpha')
            ->assertOk()
            ->assertSee('test.alpha')
            ->assertDontSee('<td class="px-4 py-3 font-mono text-xs">test.beta</td>', false);
    }

    public function test_audit_records_cannot_be_updated_or_deleted_through_the_model(): void
    {
        $log = app(AuditLogger::class)->record('test.event');

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('Update should have been refused.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('Delete should have been refused.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'test.event']);
    }

    public function test_postgres_trigger_blocks_raw_updates_and_deletes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database-level immutability is enforced on PostgreSQL only.');
        }

        $log = app(AuditLogger::class)->record('test.event');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
    }

    public function test_sensitive_metadata_is_redacted(): void
    {
        $log = app(AuditLogger::class)->record('test.event', metadata: [
            'password' => 'hunter2-secret',
            'nested' => ['api_key' => 'sk-live-123', 'safe' => 'kept'],
            'AUTHORIZATION' => 'Bearer abc',
        ]);

        $stored = json_encode(AuditLog::query()->find($log->id)->metadata);
        $this->assertStringNotContainsString('hunter2-secret', $stored);
        $this->assertStringNotContainsString('sk-live-123', $stored);
        $this->assertStringNotContainsString('Bearer abc', $stored);
        $this->assertStringContainsString('kept', $stored);
    }

    public function test_requests_carry_a_correlation_id(): void
    {
        $response = $this->get('/login');
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));

        $id = '0b6f6a3e-8d0c-4c7e-9a43-8f3a2b1c0d9e';
        $this->withHeader('X-Request-Id', $id)->get('/login')->assertHeader('X-Request-Id', $id);

        $replaced = $this->withHeader('X-Request-Id', 'not-a-uuid')->get('/login')->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($replaced));
    }
}
