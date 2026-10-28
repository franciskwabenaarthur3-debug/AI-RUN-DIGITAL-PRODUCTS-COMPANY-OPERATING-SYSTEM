<?php

namespace Tests\Feature\Maintenance;

use App\Enums\AlertSeverity;
use App\Enums\ApprovalStatus;
use App\Enums\FindingStatus;
use App\Enums\MaintenanceTaskStatus;
use App\Enums\RiskLevel;
use App\Exceptions\InvalidTransition;
use App\Models\Alert;
use App\Models\MaintenanceFinding;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ApprovalService;
use App\Services\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class MaintenanceVisitTest extends TestCase
{
    use InteractsWithMaintenance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMaintenance();
    }

    private function refused(callable $action, ?string $message = null): void
    {
        try {
            $action();
        } catch (InvalidTransition $e) {
            if ($message) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('Expected the action to be refused.');
    }

    private function riskyFinding(MaintenanceTask $task): MaintenanceFinding
    {
        return $this->maintenance()->addFinding($task, ['severity' => 'medium', 'description' => 'WordPress core is two versions behind.', 'recommendation' => 'Update core.', 'risky' => true], $this->operator);
    }

    public function test_a_completed_visit_keeps_its_evidence_and_cannot_change(): void
    {
        Http::fake(['https://acme-bakery.example' => Http::response('ok', 200)]);
        $task = $this->startedVisit();
        $task = $this->maintenance()->runHealthCheck($task, $this->operator);
        $this->assertTrue($task->health_detail['healthy']);
        $finding = $this->maintenance()->addFinding($task, ['severity' => 'low', 'description' => 'Image sizes could be smaller.', 'risky' => false], $this->operator);
        $this->maintenance()->resolveFinding($finding, FindingStatus::WontFix, 'Client is happy with it.', $this->operator);

        $done = $this->maintenance()->complete($task, 'Backups verified in the host panel; uptime 100%.', $this->operator);

        $this->assertSame(MaintenanceTaskStatus::Completed, $done->status);
        $this->assertSame($this->operator->id, $done->completed_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'maintenance.task_completed']);

        foreach ([
            fn () => $done->fresh()->update(['evidence' => 'Edited later']),
            fn () => $done->fresh()->update(['status' => MaintenanceTaskStatus::InProgress]),
            fn () => $done->fresh()->delete(),
            fn () => $finding->fresh()->update(['resolution_note' => 'Edited later']),
            fn () => $finding->fresh()->delete(),
        ] as $change) {
            try {
                $change();
                $this->fail('A closed visit changed.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('Backups verified in the host panel; uptime 100%.', $done->fresh()->evidence);
        $this->refused(fn () => $this->maintenance()->addFinding($done, ['severity' => 'low', 'description' => 'x', 'risky' => false], $this->operator));

        $this->actingAs($this->operator)->get("/maintenance/tasks/{$task->id}/report")->assertOk()
            ->assertSee('Backups verified in the host panel')->assertSee('Image sizes could be smaller.');
    }

    public function test_completion_needs_every_check_closed_findings_and_evidence(): void
    {
        $task = $this->startedVisit(checked: false);
        $this->refused(fn () => $this->maintenance()->complete($task, 'Done', $this->operator), 'checklist');
        $this->refused(fn () => $this->maintenance()->recordChecklistItem($task, 0, 'issue', null, $this->operator));

        foreach (array_keys($task->checklist) as $i) {
            $this->maintenance()->recordChecklistItem($task, $i, 'ok', null, $this->operator);
        }
        $this->riskyFinding($task);
        $this->refused(fn () => $this->maintenance()->complete($task, 'Done', $this->operator), 'still open');

        $this->assertSame(MaintenanceTaskStatus::InProgress, $task->fresh()->status);
        $this->actingAs($this->operator)->post("/maintenance/tasks/{$task->id}/complete", ['evidence' => ''])->assertSessionHasErrors('evidence');
    }

    public function test_risky_changes_need_the_founders_approval_of_the_exact_change(): void
    {
        $task = $this->startedVisit();
        $finding = $this->riskyFinding($task);

        // It cannot be marked fixed, nor the change recorded, without approval.
        $this->refused(fn () => $this->maintenance()->resolveFinding($finding, FindingStatus::Resolved, 'Updated it', $this->operator), 'approval');
        $this->refused(fn () => $this->maintenance()->recordChange($finding, 'Updated it', $this->operator), 'not been approved');

        $finding = $this->maintenance()->requestChangeApproval($finding, 'Update WordPress core to 6.6 from the host panel.', 'Restore the backup taken just before.', $this->operator);
        $approval = $finding->approvalRequest;
        $this->assertSame([FindingStatus::ApprovalRequested, ApprovalStatus::Pending, RiskLevel::High, MaintenanceService::CHANGE_ACTION], [$finding->status, $approval->status, $approval->risk_level, $approval->action_type]);
        $this->assertSame('https://acme-bakery.example', $approval->payload_snapshot['site_url']);
        $this->refused(fn () => $this->maintenance()->recordChange($finding, 'Updated it', $this->operator), 'not been approved');

        app(ApprovalService::class)->decide($approval, $this->founder, true);
        $this->assertSame(FindingStatus::Approved, $finding->fresh()->status);

        // Editing the change plan after approval voids the approval.
        MaintenanceFinding::query()->whereKey($finding->id)->toBase()->update(['change_plan' => 'Also install a new plugin.']);
        $this->refused(fn () => $this->maintenance()->recordChange($finding->fresh(), 'Updated it', $this->operator), 'Request approval again');
        $this->assertSame(ApprovalStatus::Invalidated, $approval->fresh()->status);
        $this->assertSame(FindingStatus::Approved, $finding->fresh()->status);
        $this->refused(fn () => $this->maintenance()->complete($task, 'Done', $this->operator), 'still open');

        // Asking again is allowed once the earlier approval is unusable.
        $again = $this->maintenance()->requestChangeApproval($finding->fresh(), 'Update WordPress core to 6.6.', 'Restore the backup.', $this->operator);
        $this->assertNotSame($approval->id, $again->approval_request_id);
    }

    public function test_an_approved_change_is_recorded_once_and_then_the_visit_can_close(): void
    {
        $task = $this->startedVisit();
        $finding = $this->maintenance()->requestChangeApproval($this->riskyFinding($task), 'Update core.', 'Restore backup.', $this->operator);
        app(ApprovalService::class)->decide($finding->approvalRequest, $this->founder, true);

        $this->actingAs($this->operator)->post("/maintenance/tasks/{$task->id}/findings/{$finding->id}/record-change", ['note' => 'Updated at 10:00, site checked.'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->operator)->post("/maintenance/tasks/{$task->id}/findings/{$finding->id}/record-change", ['note' => 'Updated at 10:00, site checked.', 'confirm' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(FindingStatus::Resolved, $finding->fresh()->status);
        $this->assertSame(ApprovalStatus::Executed, $finding->approvalRequest->fresh()->status);
        $this->refused(fn () => $this->maintenance()->recordChange($finding->fresh(), 'Again', $this->operator));
        $this->assertSame(MaintenanceTaskStatus::Completed, $this->maintenance()->complete($task, 'Core updated, backup kept.', $this->operator)->status);
    }

    public function test_a_rejected_change_closes_the_finding_with_the_reason(): void
    {
        $task = $this->startedVisit();
        $finding = $this->maintenance()->requestChangeApproval($this->riskyFinding($task), 'Update core.', 'Restore backup.', $this->operator);

        app(ApprovalService::class)->decide($finding->approvalRequest, $this->founder, false, 'Wait for the client to agree the cost.');

        $finding->refresh();
        $this->assertSame(FindingStatus::Rejected, $finding->status);
        $this->assertStringContainsString('Wait for the client', $finding->resolution_note);
        $this->assertSame(MaintenanceTaskStatus::Completed, $this->maintenance()->complete($task, 'Reported the outdated core to the client.', $this->operator)->status);
    }

    public function test_cancelling_a_visit_withdraws_its_open_approvals(): void
    {
        $task = $this->startedVisit();
        $finding = $this->maintenance()->requestChangeApproval($this->riskyFinding($task), 'Update core.', 'Restore backup.', $this->operator);

        $this->maintenance()->cancel($task, 'Client paused the site.', $this->operator);

        $this->assertSame(MaintenanceTaskStatus::Cancelled, $task->fresh()->status);
        $this->assertSame(ApprovalStatus::Invalidated, $finding->approvalRequest->fresh()->status);
    }

    public function test_failures_raise_alerts(): void
    {
        Http::fake(['https://acme-bakery.example' => Http::response('down', 503)]);
        $task = $this->startedVisit();

        $checked = $this->maintenance()->runHealthCheck($task, $this->operator);
        $this->assertFalse($checked->health_detail['healthy']);
        $finding = $task->findings()->sole();
        $this->assertSame('high', $finding->severity->value);
        $alert = Alert::query()->where('dedupe_key', "maintenance_finding:{$finding->id}")->sole();
        $this->assertSame([AlertSeverity::Warning, 'maintenance_task', $task->id], [$alert->severity, $alert->subject_type, $alert->subject_id]);

        $critical = $this->maintenance()->addFinding($task, ['severity' => 'critical', 'description' => 'Malware found in uploads.', 'risky' => false], $this->operator);
        $this->assertSame(AlertSeverity::Critical, Alert::query()->where('dedupe_key', "maintenance_finding:{$critical->id}")->sole()->severity);
        $this->maintenance()->addFinding($task, ['severity' => 'low', 'description' => 'Minor.', 'risky' => false], $this->operator);
        $this->assertSame(2, Alert::query()->count(), 'Low findings raise no alert.');

        $this->maintenance()->fail($task, 'Host panel unreachable.', $this->operator);
        $this->assertSame(AlertSeverity::Critical, Alert::query()->where('dedupe_key', "maintenance_failed:{$task->id}")->sole()->severity);
        $this->assertSame(MaintenanceTaskStatus::Failed, $task->fresh()->status);
    }

    public function test_the_alert_sweep_flags_overdue_visits_and_renewals_once(): void
    {
        $plan = $this->plan(['term_ends_at' => today()->addDays(10)->toDateString()]);
        $this->maintenance()->scheduleTask($plan, today(), $this->operator);
        $this->travel(2)->days();

        $this->artisan('company-os:raise-alerts')->assertSuccessful();
        $this->assertSame(0, app(AlertService::class)->sweep(), 'A second sweep raises nothing new.');

        $this->assertSame(['maintenance_overdue', 'renewal_due'], Alert::query()->orderBy('type')->pluck('type')->all());

        // Acknowledged alerts stay acknowledged; the same condition does not raise again.
        $alert = Alert::query()->where('type', 'maintenance_overdue')->sole();
        $this->actingAs($this->operator)->post("/alerts/{$alert->id}/acknowledge")->assertRedirect();
        $this->assertNotNull($alert->fresh()->acknowledged_at);
        app(AlertService::class)->sweep();
        $this->assertSame(2, Alert::query()->count());
        $this->assertSame(1, Alert::query()->open()->count());

        // A recorded renewal is a new term; its reminder is a new alert later.
        $this->maintenance()->renew($plan, today()->addYear(), 'Client email', $this->founder);
        $this->assertSame(0, app(AlertService::class)->sweep());
    }

    public function test_people_who_cannot_operate_cannot_work_visits(): void
    {
        $task = $this->startedVisit(checked: false);
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->get("/maintenance/tasks/{$task->id}")->assertOk();
        $this->actingAs($viewer)->post("/maintenance/tasks/{$task->id}/checklist", ['item' => 0, 'result' => 'ok'])->assertForbidden();
        $this->actingAs($viewer)->post("/maintenance/tasks/{$task->id}/complete", ['evidence' => 'x'])->assertForbidden();
        $this->assertSame('pending', $task->fresh()->checklist[0]['result']);
    }
}
