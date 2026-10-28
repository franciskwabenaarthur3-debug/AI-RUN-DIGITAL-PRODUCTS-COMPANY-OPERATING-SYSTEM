<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Task;
use App\Services\ApprovalService;
use App\Services\ServicePackageService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectCreationTest extends TestCase
{
    use InteractsWithProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjects();
    }

    public function test_accepting_a_proposal_creates_one_project_with_the_default_template(): void
    {
        $proposal = $this->acceptedProposal();

        $project = Project::query()->sole();
        $this->assertSame($proposal->id, $project->proposal_id);
        $this->assertSame(ProjectStatus::Created, $project->status);
        $this->assertSame($proposal->amount_minor, $project->budget_minor);
        $this->assertNotNull($project->customer_id, 'The lead was converted and the project linked to the customer.');
        $this->assertSame(today()->addWeekdays(14)->toDateString(), $project->due_date->toDateString());

        $template = config('company_os.projects.default_template');
        $this->assertSame(array_column($template, 'name'), $project->milestones()->pluck('name')->all());
        $this->assertSame(collect($template)->flatMap(fn ($m) => $m['tasks'])->all(), $project->tasks()->pluck('title')->all());
        $this->assertTrue($project->tasks()->get()->every(fn (Task $t) => $t->milestone_id !== null && $t->agent_id === null && $t->status === TaskStatus::Created));
        $this->assertSame(today()->addWeekdays(14)->toDateString(), $project->milestones()->get()->last()->due_at->toDateString());
        $this->assertCount(5, $project->handover_checklist);
        $this->assertSame(['created'], $project->events()->pluck('event_type')->all());
    }

    public function test_repeated_acceptance_and_conversion_do_not_create_duplicates(): void
    {
        $proposal = $this->acceptedProposal();
        $this->proposals()->recordResponse($proposal, 'accepted', 'Again', today(), $this->operator);
        $again = $this->projects()->createFromProposal($proposal, $this->founder);
        $this->projects()->createFromProposal($proposal, $this->operator);

        $this->assertSame(1, Project::query()->count());
        $this->assertSame(Project::query()->sole()->id, $again->id);
        $this->assertSame(count(collect(config('company_os.projects.default_template'))->flatMap(fn ($m) => $m['tasks'])), Task::query()->count());
    }

    public function test_the_database_refuses_a_second_project_for_a_proposal(): void
    {
        $proposal = $this->acceptedProposal();

        $this->expectException(UniqueConstraintViolationException::class);
        Project::query()->create(['proposal_id' => $proposal->id, 'name' => 'Copy', 'budget_minor' => 1, 'currency' => 'GHS', 'handover_checklist' => []]);
    }

    public function test_only_accepted_proposals_become_projects(): void
    {
        $proposal = $this->sentProposal();

        $this->assertThrows(fn () => $this->projects()->createFromProposal($proposal, $this->operator), InvalidTransition::class);
        $this->proposals()->recordResponse($proposal, 'declined', 'No budget', today(), $this->operator);
        $this->assertSame(0, Project::query()->count());
    }

    public function test_tasks_come_from_the_package_delivery_template(): void
    {
        $this->package->update(['delivery_template' => ServicePackageService::template("# Kick-off\n- Call the client\n# Build\n- Build pages\n- Connect the shop\n")]);

        $project = Project::query()->where('proposal_id', $this->acceptedProposal()->id)->sole();

        $this->assertSame(['Kick-off', 'Build'], $project->milestones()->pluck('name')->all());
        $this->assertSame(['Call the client'], $project->milestones()->first()->tasks()->pluck('title')->all());
        $this->assertSame(['Build pages', 'Connect the shop'], $project->milestones()->get()->last()->tasks()->pluck('title')->all());
    }

    public function test_delivery_template_text_round_trips_and_is_saved_from_the_package_form(): void
    {
        $text = "# Discovery\n- Workshop\n# Launch\n- Go live";
        $this->assertSame($text, ServicePackageService::templateText(ServicePackageService::template($text)));
        $this->assertSame([['name' => 'Delivery', 'tasks' => ['Loose task']]], ServicePackageService::template('Loose task'));
        $this->assertNull(ServicePackageService::template("  \n"));

        $this->actingAs($this->founder)->put("/service-packages/{$this->package->id}", [
            'name' => $this->package->name, 'description' => 'x', 'base_price' => '1500.00', 'currency' => 'GHS',
            'estimated_days' => 14, 'revision_limit' => 2, 'is_active' => '1', 'scope' => 'Design', 'delivery_template' => $text,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Workshop', $this->package->fresh()->delivery_template[0]['tasks'][0]);
    }

    public function test_an_accepted_change_request_extends_the_project_instead_of_creating_one(): void
    {
        $project = $this->project();
        $budget = $project->budget_minor;

        $change = $this->proposals()->create([
            'customer_id' => $project->customer_id,
            'project_id' => $project->id,
            'service_package_id' => $this->package->id,
            'title' => 'Add an online shop',
        ], $this->operator);
        $this->proposals()->submit($change, $this->operator);
        app(ApprovalService::class)->decide($change->fresh()->approvalRequest, $this->founder, true);
        $this->proposals()->send($change->fresh(), 'manual', $this->founder, 'Emailed');
        $this->proposals()->recordResponse($change->fresh(), 'accepted', 'Yes to the shop', today(), $this->operator);
        $this->proposals()->recordResponse($change->fresh(), 'accepted', 'Yes again', today(), $this->operator);

        $this->assertSame(1, Project::query()->count());
        $project->refresh();
        $this->assertSame($budget + $change->amount_minor, $project->budget_minor);
        $this->assertSame(1, $project->milestones()->where('name', "Change request {$change->fresh()->label()}")->count());
        $this->assertSame(1, $project->events()->where('event_type', 'change_request_accepted')->count());
        $this->assertArrayHasKey('project_id', $this->proposals()->payload($change->fresh()), 'The founder approves which project the change is for.');
    }

    public function test_a_change_request_goes_to_the_projects_own_client(): void
    {
        $project = $this->project();
        $other = Customer::factory()->create();

        $this->assertThrows(fn () => $this->proposals()->create([
            'customer_id' => $other->id, 'project_id' => $project->id, 'service_package_id' => $this->package->id, 'title' => 'Sneaky',
        ], $this->operator), ValidationException::class);
        $this->assertSame(1, Proposal::query()->count());
    }

    public function test_proposals_accepted_before_projects_get_one_from_the_button(): void
    {
        // As if it had been accepted before Phase 7 was installed.
        $proposal = $this->sentProposal();
        Proposal::query()->whereKey($proposal->id)->toBase()->update(['status' => 'accepted']);

        $this->actingAs($this->operator)->get("/proposals/{$proposal->id}")->assertSee('Create project');
        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/project")->assertRedirect();
        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/project")->assertRedirect();

        $project = Project::query()->sole();
        $this->actingAs($this->operator)->get("/proposals/{$proposal->id}")->assertDontSee('Create project')->assertSee($project->name);
    }
}
