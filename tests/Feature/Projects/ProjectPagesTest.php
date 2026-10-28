<?php

namespace Tests\Feature\Projects;

use App\Enums\CheckStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReleaseStatus;
use App\Models\Project;
use App\Models\ProjectRelease;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectPagesTest extends TestCase
{
    use InteractsWithProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjects();
    }

    public function test_project_pages_show_the_plan_releases_and_handover(): void
    {
        $project = $this->project();

        $this->actingAs($this->operator)->get('/projects')->assertOk()->assertSee($project->name)->assertSee('0 of 5 done');
        $this->actingAs($this->operator)->get("/projects/{$project->id}")->assertOk()
            ->assertSee('Milestones and tasks')->assertSee('Discovery')->assertSee('Collect brand assets and account access')
            ->assertSee('Deliverables')->assertSee('Releases and quality checks')->assertSee('Handover checklist')
            ->assertSee('Admin access handed over securely')->assertSee('https://staging.acme-bakery.example')
            ->assertSee('Nothing blocking right now')->assertSee('Mark done');
        $this->actingAs($this->operator)->get("/projects/{$project->id}/edit")->assertOk()->assertSee('Git repository URL');
        $this->actingAs($this->operator)->get('/tasks/'.$project->tasks()->first()->id)->assertOk()
            ->assertSee($project->name)->assertSee('Mark it done on the')->assertDontSee('Queue task');
    }

    public function test_viewers_can_read_but_not_change_projects(): void
    {
        $viewer = User::factory()->create();
        $project = $this->project();
        $task = $project->tasks()->first();

        $this->actingAs($viewer)->get("/projects/{$project->id}")->assertOk()->assertDontSee('Move the project')->assertDontSee('Mark done');
        $this->actingAs($viewer)->get("/projects/{$project->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->post("/projects/{$project->id}/status", ['status' => 'planning'])->assertForbidden();
        $this->actingAs($viewer)->post("/projects/{$project->id}/tasks/{$task->id}/complete")->assertForbidden();
        $this->actingAs($viewer)->post("/projects/{$project->id}/releases", ['environment' => 'staging', 'version' => '1', 'release_notes' => 'x', 'rollback_plan' => 'y'])->assertForbidden();
        $this->assertSame(ProjectStatus::Created, $project->fresh()->status);
    }

    public function test_operators_run_the_project_from_its_page(): void
    {
        $project = $this->project();
        $task = $project->tasks()->first();

        $this->actingAs($this->operator)->post("/projects/{$project->id}/status", ['status' => 'planning'])->assertSessionHas('status');
        $this->actingAs($this->operator)->post("/projects/{$project->id}/status", ['status' => 'cancelled'])->assertSessionHasErrors('project');
        $this->actingAs($this->operator)->post("/projects/{$project->id}/tasks/{$task->id}/complete")->assertSessionHas('status');
        $this->actingAs($this->operator)->post("/projects/{$project->id}/tasks", ['title' => 'Write FAQ', 'assignee' => "user:{$this->operator->id}"])->assertSessionHas('status');
        $this->actingAs($this->operator)->post("/projects/{$project->id}/deliverables", ['name' => 'Logo', 'type' => 'design', 'url' => 'https://user:secret@files.example/logo'])->assertSessionHasErrors('url');
        $this->actingAs($this->operator)->post("/projects/{$project->id}/handover", ['item' => 0, 'done' => 1])->assertSessionHas('status');
        $this->actingAs($this->operator)->put("/projects/{$project->id}", ['name' => $project->name, 'repository_url' => 'git@github.com:acme/site.git'])->assertSessionHasErrors('repository_url');

        $project->refresh();
        $this->assertSame(ProjectStatus::Planning, $project->status);
        $this->assertSame($this->operator->id, $project->tasks()->where('title', 'Write FAQ')->sole()->assignee_user_id);
        $this->assertTrue($project->handover_checklist[0]['done']);
        $this->assertSame(0, $project->deliverables()->count());
    }

    public function test_tasks_from_another_project_cannot_be_completed_through_this_one(): void
    {
        $project = $this->project();
        $other = Project::query()->create(['name' => 'Other', 'budget_minor' => 1, 'currency' => 'GHS', 'handover_checklist' => [], 'status' => 'created']);
        $foreign = app(TaskService::class)->create(['title' => 'Theirs', 'project_id' => $other->id], $this->operator);

        $this->actingAs($this->operator)->post("/projects/{$project->id}/tasks/{$foreign->id}/complete")->assertNotFound();
    }

    public function test_out_of_scope_feedback_leads_to_a_change_request(): void
    {
        $project = $this->project();

        $this->actingAs($this->operator)->post("/projects/{$project->id}/feedback", ['note' => 'Can you add a shop?', 'scope' => 'out_of_scope'])
            ->assertRedirect("/proposals/create?project_id={$project->id}");
        $this->actingAs($this->operator)->get("/proposals/create?project_id={$project->id}")->assertOk()
            ->assertSee('This is a change request for project')->assertSee("Change request: {$project->name}", false);
        $this->actingAs($this->operator)->get("/projects/{$project->id}")->assertSee('Outside scope')->assertSee('Can you add a shop?');
    }

    public function test_the_release_flow_through_the_pages(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $project = $this->moveTo($this->project(), ProjectStatus::Planning, ProjectStatus::InProgress, ProjectStatus::InternalReview);

        $this->actingAs($this->operator)->post("/projects/{$project->id}/releases", [
            'environment' => 'staging', 'version' => '1.0.0', 'commit_reference' => 'v1.0.0', 'release_notes' => 'Full site', 'rollback_plan' => 'Restore backup',
        ])->assertRedirect();
        $staging = ProjectRelease::query()->sole();
        $this->assertSame('https://staging.acme-bakery.example', $staging->target_url);

        $this->actingAs($this->operator)->get("/releases/{$staging->id}")->assertOk()->assertSee('Automated tests passed')->assertSee('mandatory')->assertSee('Record the staging deployment');
        $check = $staging->checks()->first();
        $this->actingAs($this->operator)->post("/releases/{$staging->id}/checks/{$check->id}", ['status' => 'passed', 'evidence' => 'https://ci.example/runs/42'])->assertSessionHas('status');
        $this->actingAs($this->operator)->post("/releases/{$staging->id}/deploy-staging", ['note' => 'Uploaded via SFTP'])->assertSessionHas('status');
        $this->actingAs($this->operator)->post("/releases/{$staging->id}/health-check")->assertSessionHas('status', 'Healthy: HTTP 200.');
        $this->assertSame(ReleaseStatus::Verified, $staging->fresh()->status);
        $this->assertSame(CheckStatus::Passed, $check->fresh()->status);

        $project = $this->moveTo($project, ProjectStatus::ClientReview, ProjectStatus::ApprovedForRelease);
        $this->actingAs($this->operator)->post("/projects/{$project->id}/releases", ['environment' => 'production', 'version' => '1.0.0', 'release_notes' => 'Full site', 'rollback_plan' => 'Restore backup']);
        $production = ProjectRelease::query()->where('environment', 'production')->sole();

        $this->actingAs($this->operator)->post("/releases/{$production->id}/submit")->assertSessionHasErrors('release');
        $this->passChecks($production);
        $this->actingAs($this->operator)->post("/releases/{$production->id}/submit")->assertSessionHas('status');
        $production->refresh();

        $this->actingAs($this->founder)->get("/approvals/{$production->approval_request_id}")->assertOk()
            ->assertSee("Release #{$production->id}")->assertSee('this approval cannot override a failed check');
        $this->actingAs($this->founder)->post("/approvals/{$production->approval_request_id}/approve", ['confirm' => '1'])->assertSessionHasNoErrors();

        $this->actingAs($this->operator)->get("/releases/{$production->id}")->assertSee('Waiting for the founder to deploy it');
        $this->actingAs($this->operator)->post("/releases/{$production->id}/deploy", ['note' => 'x', 'confirm' => '1'])->assertForbidden();
        $this->actingAs($this->founder)->post("/releases/{$production->id}/deploy", ['note' => 'Deployed v1.0.0'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->founder)->post("/releases/{$production->id}/deploy", ['note' => 'Deployed v1.0.0', 'confirm' => '1'])->assertSessionHas('status');
        $this->actingAs($this->operator)->post("/releases/{$production->id}/health-check")->assertSessionHas('status');

        $this->assertSame(ProjectStatus::Delivered, $project->fresh()->status);
        $this->actingAs($this->operator)->get("/projects/{$project->id}")->assertSee('Delivered');
    }

    public function test_release_form_validation(): void
    {
        $project = $this->project();

        $this->actingAs($this->operator)->post("/projects/{$project->id}/releases", ['environment' => 'staging', 'version' => '../1', 'release_notes' => 'x', 'rollback_plan' => 'y'])->assertSessionHasErrors('version');
        $this->actingAs($this->operator)->post("/projects/{$project->id}/releases", ['environment' => 'staging', 'version' => '1', 'release_notes' => 'x'])->assertSessionHasErrors('rollback_plan');
        $this->actingAs($this->operator)->post("/projects/{$project->id}/releases", ['environment' => 'staging', 'version' => '1', 'release_notes' => 'x', 'rollback_plan' => 'y', 'target_url' => 'ftp://files.example'])->assertSessionHasErrors('target_url');
        $this->assertSame(0, ProjectRelease::query()->count());
    }

    public function test_dashboard_and_navigation_show_projects(): void
    {
        $project = $this->project();
        $project->milestones()->first()->update(['due_at' => today()->subDays(3)]);

        $this->actingAs($this->founder)->get('/dashboard')->assertOk()
            ->assertSee('Active projects')->assertSee('1 overdue milestone')->assertSee('0 releases awaiting approval')
            ->assertSee(route('projects.index'));
        $this->actingAs($this->founder)->get("/projects/{$project->id}")->assertSee('is overdue');
    }
}
