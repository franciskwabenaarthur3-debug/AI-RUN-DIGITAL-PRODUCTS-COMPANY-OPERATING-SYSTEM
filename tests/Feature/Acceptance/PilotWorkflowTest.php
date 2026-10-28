<?php

namespace Tests\Feature\Acceptance;

use App\Enums\ApprovalStatus;
use App\Enums\MaintenanceTaskStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReleaseStatus;
use App\Enums\ReportPeriod;
use App\Enums\ToolExecutionStatus;
use App\Models\ApprovalRequest;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceTask;
use App\Models\Project;
use App\Models\Proposal;
use App\Services\OperatingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Governance\InteractsWithGovernance;
use Tests\Feature\Projects\InteractsWithProjects;
use Tests\TestCase;

/**
 * The whole pilot path for a test client, through the pages where a
 * person would act: an agent drafts the proposal, the founder approves
 * and sends it, the client accepts, the project is delivered through
 * staging and an approved production release, and a maintenance visit
 * is completed and reported.
 */
class PilotWorkflowTest extends TestCase
{
    use InteractsWithGovernance, InteractsWithProjects {
        InteractsWithProjects::setUpProjects as baseSetUpProjects;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGovernance();
        $this->baseSetUpProjects();
        Http::fake(['*' => Http::response('<h1>Acme Bakery</h1>', 200)]);
    }

    public function test_a_test_client_goes_from_lead_to_maintenance_report(): void
    {
        // 1. The Sales Agent drafts a proposal from the package; it cannot send it.
        $this->grant('draft_proposal');
        $draft = $this->useTool('draft_proposal', ['lead_id' => $this->lead->id, 'service_package_id' => $this->package->id, 'title' => 'New website for Acme Bakery']);
        $this->assertSame(ToolExecutionStatus::Succeeded, $draft->status, (string) $draft->error_message);
        $proposal = $this->proposals()->submit(Proposal::query()->sole(), $this->operator);

        // 2. The founder approves the exact version and records sending it.
        $this->actingAs($this->founder)->post("/approvals/{$proposal->approval_request_id}/approve", ['confirm' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->founder)->post("/proposals/{$proposal->id}/send", ['method' => 'manual', 'note' => 'Emailed from my inbox', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(ProposalStatus::Sent, $proposal->fresh()->status);

        // 3. The client accepts: one customer, one project, even if recorded twice.
        $accept = ['outcome' => 'accepted', 'note' => 'Client replied yes by email', 'responded_at' => today()->toDateString(), 'confirm' => '1'];
        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/respond", $accept)->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/respond", $accept);
        $project = Project::query()->sole();
        $this->assertSame($this->lead->fresh()->customer_id, $project->customer_id);
        $this->assertNotNull($project->customer_id);
        $project->update(['staging_url' => 'https://staging.acme-bakery.example', 'production_url' => 'https://acme-bakery.example']);

        // 4. Delivery: staging verified, client approval, production approved by the founder and recorded.
        $project = $this->moveTo($project->fresh(), ProjectStatus::Planning, ProjectStatus::InProgress, ProjectStatus::InternalReview);
        $this->verifiedStaging($project);
        $project = $this->moveTo($project, ProjectStatus::ClientReview, ProjectStatus::ApprovedForRelease);
        $release = $this->approvedProduction($project);
        $this->actingAs($this->founder)->post("/releases/{$release->id}/deploy", ['note' => 'Deployed with the host panel at 10:00', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->operator)->post("/releases/{$release->id}/health-check")->assertSessionHasNoErrors();
        $this->assertSame(ReleaseStatus::Verified, $release->fresh()->status);
        $this->assertSame(ProjectStatus::Delivered, $project->fresh()->status);

        // 5. Maintenance: the founder offers a plan; the scheduler books the visit; a person does it.
        $this->actingAs($this->founder)->post('/maintenance/plans', [
            'customer_id' => $project->customer_id, 'project_id' => $project->id, 'plan_name' => 'Website care',
            'billing_frequency' => 'monthly', 'service_interval' => 'monthly', 'amount' => '450', 'currency' => 'GHS',
            'next_service_at' => today()->addDays(2)->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->artisan('company-os:schedule-maintenance')->assertSuccessful();
        $this->artisan('company-os:schedule-maintenance')->assertSuccessful();
        $visit = MaintenanceTask::query()->sole();

        $this->actingAs($this->operator)->post("/maintenance/tasks/{$visit->id}/start");
        foreach (array_keys($visit->checklist) as $i) {
            $this->actingAs($this->operator)->post("/maintenance/tasks/{$visit->id}/checklist", ['item' => $i, 'result' => 'ok']);
        }
        $this->actingAs($this->operator)->post("/maintenance/tasks/{$visit->id}/health-check");
        $this->actingAs($this->operator)->post("/maintenance/tasks/{$visit->id}/complete", ['evidence' => 'Backups in the host panel; all checks fine.'])->assertSessionHasNoErrors();
        $this->assertSame(MaintenanceTaskStatus::Completed, $visit->fresh()->status);
        $this->actingAs($this->operator)->get("/maintenance/tasks/{$visit->id}/report")->assertOk()->assertSee('Backups in the host panel');

        // 6. The report counts what happened, from records.
        $report = app(OperatingReportService::class)->build(ReportPeriod::Monthly, now());
        $this->assertSame([1, 1, 1, 1], [$report['sales']['proposals_sent'], $report['sales']['proposals_accepted'], $report['projects']['delivered'], $report['maintenance']['visits_completed']]);
        $this->assertSame(100, $report['projects']['on_time_rate_percent']);
        $this->actingAs($this->founder)->get('/dashboard')->assertOk();

        // Nothing was done twice and nothing is left half-finished.
        $this->assertSame(1, Project::query()->count());
        $this->assertSame(1, MaintenancePlan::query()->count());
        $this->assertSame(0, ApprovalRequest::query()->whereIn('status', [ApprovalStatus::Failed, ApprovalStatus::NeedsReconciliation, ApprovalStatus::Executing])->count());
        foreach (['proposal.sent', 'project.created', 'release.deployed', 'maintenance.task_completed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
    }
}
