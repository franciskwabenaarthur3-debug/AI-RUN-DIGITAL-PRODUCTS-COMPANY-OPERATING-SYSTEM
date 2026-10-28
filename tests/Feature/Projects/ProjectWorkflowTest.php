<?php

namespace Tests\Feature\Projects;

use App\Enums\DeliverableStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Agent;
use App\Models\Project;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectWorkflowTest extends TestCase
{
    use InteractsWithProjects;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProjects();
    }

    public function test_status_transitions_follow_the_lifecycle_and_need_notes_where_it_matters(): void
    {
        $project = $this->project();

        $this->assertThrows(fn () => $this->projects()->changeStatus($project, ProjectStatus::InProgress, $this->operator), InvalidTransition::class);
        $this->assertThrows(fn () => $this->projects()->changeStatus($project, ProjectStatus::OnHold, $this->operator), InvalidTransition::class);
        $project = $this->moveTo($project, ProjectStatus::Planning, ProjectStatus::InProgress);
        $project = $this->projects()->changeStatus($project, ProjectStatus::Blocked, $this->operator, 'Waiting for content');
        $project = $this->projects()->changeStatus($project, ProjectStatus::InProgress, $this->operator);

        $this->assertSame(ProjectStatus::InProgress, $project->status);
        $this->assertSame(['created', 'status_changed', 'status_changed', 'status_changed', 'status_changed'], $project->events()->pluck('event_type')->all());
        $this->assertSame([], ProjectStatus::Closed->allowedTransitions());
        $this->assertNotContains(ProjectStatus::Delivered, ProjectStatus::ApprovedForRelease->manualTransitions());
    }

    public function test_delivered_comes_only_from_a_verified_release_and_closing_needs_the_handover(): void
    {
        $project = $this->readyProject();
        $this->assertThrows(fn () => $this->projects()->changeStatus($project, ProjectStatus::Delivered, $this->operator), InvalidTransition::class);

        $release = $this->releases()->recordDeployment($this->approvedProduction($project), 'Deployed', $this->founder);
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->releases()->runHealthCheck($release, $this->operator);
        $project->refresh();
        $this->assertSame(ProjectStatus::Delivered, $project->status);

        $this->assertThrows(fn () => $this->projects()->changeStatus($project, ProjectStatus::Closed, $this->operator), InvalidTransition::class);
        foreach (array_keys($project->handover_checklist) as $i) {
            $this->projects()->setHandoverItem($project, $i, true, $this->operator);
        }
        $project = $this->projects()->changeStatus($project->fresh(), ProjectStatus::Closed, $this->operator);
        $this->assertSame(ProjectStatus::Closed, $project->status);
        $this->assertNotNull($project->closed_at);
    }

    public function test_people_complete_their_own_tasks_and_agent_tasks_go_through_review(): void
    {
        $project = $this->project();
        $task = $project->tasks()->first();
        $agentTask = $this->projects()->addTask($project, ['title' => 'Draft copy', 'agent_id' => Agent::query()->first()?->id ?? Agent::factory()->create()->id], $this->operator);

        app(TaskService::class)->completeByPerson($task, $this->operator, 'Call done');
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->assertThrows(fn () => app(TaskService::class)->completeByPerson($task->fresh(), $this->operator), InvalidTransition::class);
        $this->assertThrows(fn () => app(TaskService::class)->completeByPerson($agentTask, $this->operator), InvalidTransition::class);
        $this->assertSame(TaskStatus::Created, $agentTask->fresh()->status);
    }

    public function test_a_milestone_completes_only_when_its_tasks_are_done(): void
    {
        $project = $this->project();
        $milestone = $project->milestones()->first();

        $this->assertThrows(fn () => $this->projects()->setMilestoneStatus($milestone, MilestoneStatus::Completed, $this->operator), InvalidTransition::class);
        foreach ($milestone->tasks as $task) {
            app(TaskService::class)->completeByPerson($task, $this->operator);
        }
        $this->assertSame(MilestoneStatus::Completed, $this->projects()->setMilestoneStatus($milestone, MilestoneStatus::Completed, $this->operator)->status);
    }

    public function test_tasks_and_deliverables_cannot_point_at_another_projects_milestone(): void
    {
        $project = $this->project();
        $other = Project::query()->create(['name' => 'Other', 'budget_minor' => 1, 'currency' => 'GHS', 'handover_checklist' => [], 'status' => 'created']);
        $foreign = $other->milestones()->create(['position' => 0, 'name' => 'Theirs', 'status' => 'pending']);

        $this->assertThrows(fn () => $this->projects()->addTask($project, ['title' => 'x', 'milestone_id' => $foreign->id], $this->operator), InvalidTransition::class);
        $this->assertThrows(fn () => $this->projects()->addDeliverable($project, ['name' => 'x', 'type' => 'website', 'milestone_id' => $foreign->id], $this->operator), InvalidTransition::class);
    }

    public function test_a_new_deliverable_link_is_a_new_version(): void
    {
        $deliverable = $this->projects()->addDeliverable($this->project(), ['name' => 'Homepage design', 'type' => 'design', 'url' => 'https://figma.example/v1'], $this->operator);

        $deliverable = $this->projects()->updateDeliverable($deliverable, DeliverableStatus::InReview, null, $this->operator);
        $this->assertSame(1, $deliverable->version);
        $deliverable = $this->projects()->updateDeliverable($deliverable, DeliverableStatus::InReview, 'https://figma.example/v2', $this->operator);
        $this->assertSame(2, $deliverable->version);
        $this->assertSame('https://figma.example/v2', $deliverable->url);
    }

    public function test_project_events_are_append_only(): void
    {
        $event = $this->project()->events()->first();

        $this->assertThrows(fn () => $event->update(['note' => 'rewritten']), \LogicException::class);
        $this->assertThrows(fn () => $event->delete(), \LogicException::class);
    }
}
