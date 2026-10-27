<?php

namespace Tests\Feature\Agents;

use App\Enums\AgentRole;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = Agent::factory()->role(AgentRole::Marketing)->enabled()->create();
    }

    public function test_operator_creates_and_queues_a_task_from_the_form(): void
    {
        $operator = User::factory()->operator()->create();

        $response = $this->actingAs($operator)->post('/tasks', [
            'title' => 'Draft landing page copy',
            'description' => 'For the bakery',
            'instructions' => 'Friendly tone',
            'agent_id' => $this->agent->id,
            'priority' => 'high',
            'queue_now' => '1',
        ]);

        $task = Task::query()->sole();
        $response->assertRedirect(route('tasks.show', $task));
        $this->assertSame(['instructions' => 'Friendly tone'], $task->input);
        $this->assertSame(TaskStatus::AwaitingReview, $task->status);
        $this->assertSame('user', $task->created_by_type);
        $this->assertSame($operator->id, $task->created_by_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.created']);

        $this->actingAs($operator)->get("/tasks/{$task->id}")->assertOk()->assertSee('Accept result')->assertSee('[Mock output]');
    }

    public function test_invalid_task_is_rejected(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->post('/tasks', ['title' => '', 'agent_id' => 9999, 'priority' => 'whenever', 'depends_on' => [12345]])
            ->assertSessionHasErrors(['title', 'agent_id', 'priority', 'depends_on.0']);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_status_cannot_be_injected_through_the_form(): void
    {
        $this->actingAs(User::factory()->operator()->create())->post('/tasks', [
            'title' => 'x', 'agent_id' => $this->agent->id, 'priority' => 'normal',
            'status' => 'completed', 'result' => ['kind' => 'report'], 'attempts' => 99,
        ]);

        $task = Task::query()->sole();
        $this->assertSame(TaskStatus::Created, $task->status);
        $this->assertNull($task->result);
        $this->assertSame(0, $task->attempts);
    }

    public function test_review_accept_and_reject(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->post('/tasks', ['title' => 'A', 'agent_id' => $this->agent->id, 'priority' => 'normal', 'queue_now' => '1']);
        $this->actingAs($operator)->post('/tasks', ['title' => 'B', 'agent_id' => $this->agent->id, 'priority' => 'normal', 'queue_now' => '1']);
        [$a, $b] = Task::query()->orderBy('id')->get()->all();

        $this->actingAs($operator)->post("/tasks/{$a->id}/accept")->assertSessionHas('status');
        $this->assertSame(TaskStatus::Completed, $a->fresh()->status);

        $this->actingAs($operator)->post("/tasks/{$b->id}/reject")->assertSessionHasErrors('reason');
        $this->actingAs($operator)->post("/tasks/{$b->id}/reject", ['reason' => 'Off brand'])->assertSessionHas('status');
        $this->assertSame(TaskStatus::Failed, $b->fresh()->status);
        $this->assertStringContainsString('Off brand', $b->fresh()->failure_reason);

        // Accepting twice is refused by the state machine.
        $this->actingAs($operator)->post("/tasks/{$a->id}/accept")->assertSessionHasErrors('task');
    }

    public function test_retry_and_cancel(): void
    {
        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->post('/tasks', ['title' => 'x', 'agent_id' => $this->agent->id, 'priority' => 'normal']);
        $task = Task::query()->sole();
        $task->forceFill(['input' => ['mock_scenario' => 'fatal']])->save();

        $this->actingAs($operator)->post("/tasks/{$task->id}/queue");
        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);

        $task->forceFill(['input' => null])->save();
        $this->actingAs($operator)->post("/tasks/{$task->id}/retry")->assertSessionHas('status');
        $this->assertSame(TaskStatus::AwaitingReview, $task->fresh()->status);
        $this->assertSame(2, $task->fresh()->attempts);

        $this->actingAs($operator)->post("/tasks/{$task->id}/cancel")->assertSessionHasErrors('confirm');
        $this->actingAs($operator)->post("/tasks/{$task->id}/cancel", ['confirm' => '1', 'reason' => 'Not needed'])->assertSessionHas('status');
        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);

        $this->actingAs($operator)->post("/tasks/{$task->id}/retry")->assertSessionHasErrors('task');
    }

    public function test_viewer_cannot_create_or_operate_tasks(): void
    {
        $viewer = User::factory()->create();
        $task = Task::factory()->create(['agent_id' => $this->agent->id]);

        $this->actingAs($viewer)->get('/tasks')->assertOk()->assertDontSee('New task');
        $this->actingAs($viewer)->get("/tasks/{$task->id}")->assertOk()->assertDontSee('Queue task');
        $this->actingAs($viewer)->get('/tasks/create')->assertForbidden();
        $this->actingAs($viewer)->post('/tasks', ['title' => 'x', 'agent_id' => $this->agent->id, 'priority' => 'normal'])->assertForbidden();
        foreach (['queue', 'retry', 'cancel', 'accept', 'reject'] as $action) {
            $this->actingAs($viewer)->post("/tasks/{$task->id}/{$action}", ['confirm' => '1', 'reason' => 'x'])->assertForbidden();
        }

        $this->assertSame(TaskStatus::Created, $task->fresh()->status);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_task_list_filters_and_dashboard_counts(): void
    {
        $user = User::factory()->founder()->create();
        Task::factory()->create(['title' => 'Alpha report', 'agent_id' => $this->agent->id]);
        Task::factory()->status(TaskStatus::Failed)->create(['title' => 'Beta copy', 'agent_id' => $this->agent->id]);

        $this->actingAs($user)->get('/tasks?q=alpha')->assertSee('Alpha report')->assertDontSee('Beta copy');
        $this->actingAs($user)->get('/tasks?status=failed')->assertSee('Beta copy')->assertDontSee('Alpha report');
        $this->actingAs($user)->get('/tasks?status=nonsense')->assertSessionHasErrors('status');

        $dashboard = $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertSame(1, $dashboard->viewData('tasksByStatus')['failed']);
    }

    public function test_guests_cannot_reach_agent_or_task_routes(): void
    {
        $task = Task::factory()->create(['agent_id' => $this->agent->id]);

        $this->get('/agents')->assertRedirect('/login');
        $this->get('/tasks')->assertRedirect('/login');
        $this->post("/tasks/{$task->id}/queue")->assertRedirect('/login');
        $this->put("/agents/{$this->agent->id}/status")->assertRedirect('/login');
    }
}
