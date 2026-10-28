<?php

namespace Tests\Feature\Maintenance;

use App\Enums\ReportPeriod;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use App\Models\OperatingReport;
use App\Models\User;
use App\Services\AlertService;
use App\Services\ApprovalService;
use App\Services\OperatingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenancePagesTest extends TestCase
{
    use InteractsWithMaintenance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMaintenance();
    }

    public function test_the_founder_creates_a_plan_from_the_project_page(): void
    {
        $this->actingAs($this->founder)->get("/projects/{$this->site->id}")->assertOk()->assertSee('Offer a maintenance plan');
        $this->actingAs($this->founder)->get("/maintenance/plans/create?project_id={$this->site->id}")->assertOk()->assertSee($this->site->name);

        $this->actingAs($this->founder)->post('/maintenance/plans', [
            'customer_id' => $this->site->customer_id,
            'project_id' => $this->site->id,
            'plan_name' => 'Care plan',
            'billing_frequency' => 'monthly',
            'service_interval' => 'quarterly',
            'amount' => '450.00',
            'currency' => 'GHS',
            'checklist' => "- Check backups\n- Check forms",
        ])->assertRedirect();

        $plan = MaintenancePlan::query()->sole();
        $this->assertSame([45_000, ['Check backups', 'Check forms'], 'quarterly'], [$plan->amount_minor, $plan->checklist, $plan->service_interval->value]);
        $this->actingAs($this->operator)->get("/maintenance/plans/{$plan->id}/edit")->assertForbidden();
    }

    public function test_pages_show_visits_findings_approvals_alerts_and_reports(): void
    {
        $task = $this->startedVisit();
        $finding = $this->maintenance()->requestChangeApproval(
            $this->maintenance()->addFinding($task, ['severity' => 'high', 'description' => 'Contact form fails to send.', 'risky' => true], $this->operator),
            'Replace the SMTP password in the form plugin.', 'Put the old setting back.', $this->operator);
        app(AlertService::class)->sweep();
        app(OperatingReportService::class)->store(ReportPeriod::Daily, now()->subDay());

        $this->actingAs($this->founder);
        $this->get('/maintenance')->assertOk()->assertSee('Care plan');
        $this->get("/maintenance/plans/{$task->maintenance_plan_id}")->assertOk()->assertSee($task->title);
        $this->get("/maintenance/tasks/{$task->id}")->assertOk()->assertSee('Contact form fails to send.')->assertSee('Replace the SMTP password')->assertSee("#{$finding->approval_request_id}");
        $this->get("/approvals/{$finding->approval_request_id}")->assertOk()->assertSee("Maintenance finding #{$finding->id}")->assertSee('Company OS does not change the site');
        $this->get('/alerts')->assertOk()->assertSee('High finding');
        $this->get('/reports')->assertOk()->assertSee('Recommended priorities')->assertSee('Not recorded in Company OS');
        $this->get('/reports?period=monthly')->assertOk();
        $this->get('/reports/costs')->assertOk()->assertSee('Last six months');
        $this->get('/reports/'.OperatingReport::query()->sole()->id)->assertOk()->assertSee('kept as it was');
        $this->get('/dashboard')->assertOk()->assertSee('Maintenance visits due')->assertSee('Open alerts')->assertSee('Recommended priorities');

        app(ApprovalService::class)->decide($finding->approvalRequest, $this->founder, true);
        $this->get("/maintenance/tasks/{$task->id}")->assertOk()->assertSee('Record the approved change');
    }

    public function test_viewers_see_maintenance_but_not_reports_or_actions(): void
    {
        $plan = $this->plan();
        $task = $this->maintenance()->scheduleTask($plan, today(), $this->operator);
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->get('/maintenance')->assertOk();
        $this->actingAs($viewer)->get("/maintenance/tasks/{$task->id}")->assertOk()->assertDontSee('Start the visit');
        $this->actingAs($viewer)->post("/maintenance/tasks/{$task->id}/start")->assertForbidden();
        $this->actingAs($viewer)->post("/maintenance/plans/{$plan->id}/schedule", ['date' => today()->addDay()->toDateString()])->assertForbidden();
        $this->actingAs($viewer)->get('/reports')->assertForbidden();
        $this->actingAs($viewer)->get('/reports/costs')->assertForbidden();
        $this->actingAs($viewer)->get('/dashboard')->assertOk()->assertDontSee('Recommended priorities');
        $this->assertSame(1, MaintenanceTask::query()->count());
    }
}
