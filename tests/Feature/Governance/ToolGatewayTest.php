<?php

namespace Tests\Feature\Governance;

use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolGateway;
use App\Agents\Tools\ToolRegistry;
use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\TaskStatus;
use App\Enums\ToolExecutionStatus;
use App\Models\ApprovalRequest;
use App\Models\CompanySetting;
use App\Models\Lead;
use App\Models\Task;
use App\Models\ToolExecution;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ToolGatewayTest extends TestCase
{
    use InteractsWithGovernance;
    use RefreshDatabase;

    private const LEAD = ['company_name' => 'Acme Bakery', 'website' => 'https://acme-bakery.example', 'source_reference' => 'https://directory.example/acme'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGovernance();
    }

    private function assertDenied(ToolExecution $execution, string $code): void
    {
        $this->assertSame(ToolExecutionStatus::Denied, $execution->status);
        $this->assertSame($code, $execution->error_code);
    }

    public function test_prohibited_tools_are_refused_even_with_a_permission_row(): void
    {
        $this->agent->permissions()->create(['tool_name' => 'execute_shell', 'resource_scope' => '*', 'allowed' => true]);

        $execution = $this->useTool('execute_shell', ['command' => 'rm -rf /']);

        $this->assertDenied($execution, 'prohibited');
        $this->assertDatabaseHas('audit_logs', ['action' => 'tool.prohibited', 'actor_type' => 'agent', 'actor_id' => $this->agent->id]);
    }

    public function test_prohibited_tools_cannot_be_registered(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ToolRegistry::class)->register(new class extends FakeReleaseTool
        {
            public function name(): string
            {
                return 'grant_permission';
            }
        });
    }

    public function test_unknown_and_unimplemented_tools_are_refused(): void
    {
        $this->grant('analyze_website');

        $this->assertDenied($this->useTool('make_coffee', []), 'unknown_tool');
        $this->assertDenied($this->useTool('analyze_website', ['url' => 'https://example.com']), 'unknown_tool');
    }

    public function test_tools_without_permission_are_refused(): void
    {
        $this->assertDenied($this->useTool('create_lead', self::LEAD), 'not_permitted');
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_disabled_agent_and_emergency_pause_refuse_calls(): void
    {
        $this->grant('create_lead');
        $context = $this->context();

        CompanySetting::current()->forceFill(['operating_status' => 'paused'])->save();
        $this->assertDenied($this->useTool('create_lead', self::LEAD, $context), 'paused');

        CompanySetting::current()->forceFill(['operating_status' => 'active'])->save();
        $this->agent->forceFill(['status' => AgentStatus::Disabled])->save();
        $this->assertDenied($this->useTool('create_lead', self::LEAD, $context), 'agent_disabled');
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_input_is_validated_and_unknown_fields_are_rejected(): void
    {
        $this->grant('create_lead');

        $this->assertDenied($this->useTool('create_lead', self::LEAD + ['status' => 'converted']), 'invalid_input');
        $this->assertDenied($this->useTool('create_lead', ['company_name' => 'No source']), 'invalid_input');
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_create_lead_records_the_agent_as_actor(): void
    {
        $this->grant('create_lead');

        $execution = $this->useTool('create_lead', self::LEAD);

        $this->assertSame(ToolExecutionStatus::Succeeded, $execution->status);
        $lead = Lead::query()->sole();
        $this->assertSame(LeadSource::Research, $lead->source);
        $this->assertNull($lead->created_by);
        $this->assertSame(['lead_id' => $lead->id, 'company_name' => 'Acme Bakery'], $execution->result_summary);
        $this->assertSame('agent', $lead->activities()->sole()->actor_type);
        $this->assertSame($this->agent->name, $lead->activities()->sole()->actorName());
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.created', 'actor_type' => 'agent', 'actor_id' => $this->agent->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tool.succeeded', 'actor_type' => 'agent']);
    }

    public function test_duplicate_leads_fail_cleanly(): void
    {
        $this->grant('create_lead');
        $this->useTool('create_lead', self::LEAD);

        $execution = $this->useTool('create_lead', ['company_name' => 'Acme again', 'website' => 'acme-bakery.example/about', 'source_reference' => 'x']);

        $this->assertSame(ToolExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString('already exists', $execution->error_message);
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_repeating_a_call_in_the_same_run_returns_the_first_execution(): void
    {
        $this->grant('create_lead');
        $context = $this->context();

        $first = $this->useTool('create_lead', self::LEAD, $context);
        $again = $this->useTool('create_lead', array_reverse(self::LEAD, true), $context);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Lead::query()->count());
        $this->assertSame(1, ToolExecution::query()->count());
    }

    public function test_each_run_has_a_tool_call_limit(): void
    {
        $this->grant('create_lead');
        $this->agent->forceFill(['max_tool_calls_per_run' => 2])->save();
        $context = $this->context();

        $this->useTool('create_lead', ['company_name' => 'One', 'source_reference' => 'a'], $context);
        $this->useTool('create_lead', ['company_name' => 'Two', 'source_reference' => 'b'], $context);
        $third = $this->useTool('create_lead', ['company_name' => 'Three', 'source_reference' => 'c'], $context);

        $this->assertDenied($third, 'tool_call_limit');
        $this->assertSame(2, Lead::query()->count());
        $this->assertSame(ToolExecutionStatus::Succeeded, $this->useTool('create_lead', ['company_name' => 'Three', 'source_reference' => 'c'])->status, 'A new run has its own allowance.');
    }

    public function test_agents_may_only_set_statuses_that_describe_their_own_work(): void
    {
        $this->grant('update_lead_status');
        $lead = Lead::factory()->create();

        $ok = $this->useTool('update_lead_status', ['lead_id' => $lead->id, 'status' => 'qualified', 'reason' => 'Fits the package.']);
        $this->assertSame(ToolExecutionStatus::Succeeded, $ok->status);
        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->status);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'actor_type' => 'agent', 'actor_id' => $this->agent->id]);

        foreach (['contacted', 'converted', 'unsubscribed'] as $status) {
            $this->assertDenied($this->useTool('update_lead_status', ['lead_id' => $lead->id, 'status' => $status, 'reason' => 'x']), 'invalid_input');
        }

        $bad = $this->useTool('update_lead_status', ['lead_id' => $lead->id, 'status' => 'researched', 'reason' => 'Backwards']);
        $this->assertSame(ToolExecutionStatus::Failed, $bad->status);
        $this->assertStringContainsString('cannot move', $bad->error_message);
    }

    public function test_create_project_task_proposes_an_unqueued_subtask(): void
    {
        $this->grant('create_project_task');
        $parent = Task::factory()->create(['agent_id' => $this->agent->id]);
        $context = $this->context($this->agentRun($parent));

        $execution = $this->useTool('create_project_task', ['title' => 'Write test plan', 'priority' => 'high'], $context);

        $this->assertSame(ToolExecutionStatus::Succeeded, $execution->status);
        $child = Task::query()->findOrFail($execution->result_summary['task_id']);
        $this->assertSame(TaskStatus::Created, $child->status);
        $this->assertSame($parent->id, $child->parent_task_id);
        $this->assertSame($this->agent->name, $child->creatorName());

        $orphan = app(ToolGateway::class)->call(new ToolContext($this->agent), 'create_project_task', ['title' => 'No parent']);
        $this->assertSame(ToolExecutionStatus::Failed, $orphan->status);
    }

    public function test_request_approval_creates_a_decision_only_request(): void
    {
        $this->grant('request_approval');
        $lead = Lead::factory()->create();

        $execution = $this->useTool('request_approval', [
            'action' => 'send_outreach',
            'summary' => 'Send the drafted intro email to Acme.',
            'reason' => 'Lead is qualified.',
            'risk_level' => 'medium',
            'rollback_plan' => 'Send a correction email.',
            'evidence' => ['Qualified on 2026-10-27'],
            'lead_id' => $lead->id,
        ]);

        $approval = ApprovalRequest::query()->findOrFail($execution->result_summary['approval_request_id']);
        $this->assertSame('agent.send_outreach', $approval->action_type);
        $this->assertSame(['lead', $lead->id], [$approval->resource_type, $approval->resource_id]);
        $this->assertSame(['Qualified on 2026-10-27'], $approval->evidence);

        app(ApprovalService::class)->decide($approval, $this->founder, true);
        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status, 'Nothing executes; the decision is recorded.');
    }

    public function test_agent_run_can_call_tools_through_the_runtime(): void
    {
        $this->grant('create_lead', 'prepare_release');
        $operator = User::factory()->operator()->create();
        $tasks = app(TaskService::class);
        $task = $tasks->create(['title' => 'Find bakeries', 'agent_id' => $this->agent->id, 'input' => [
            'mock_scenario' => 'use_tool',
            'mock_tool_calls' => [
                ['tool' => 'create_lead', 'input' => self::LEAD],
                ['tool' => 'prepare_release', 'input' => ['version' => '1.0.0']],
                ['tool' => 'execute_shell', 'input' => ['command' => 'ls']],
            ],
        ]], $operator);

        $tasks->queue($task, $operator);
        $task->refresh();

        $this->assertSame(TaskStatus::AwaitingReview, $task->status);
        $this->assertSame([
            'Call 1: create_lead -> succeeded',
            'Call 2: prepare_release -> awaiting_approval',
            'Call 3: execute_shell -> denied (prohibited)',
        ], $task->result['items']);
        $run = $task->runs()->sole();
        $this->assertSame(3, ToolExecution::query()->where('agent_run_id', $run->id)->count());
        $this->assertSame($task->id, ApprovalRequest::query()->sole()->task_id);
        $this->assertSame(1, Lead::query()->count());

        $this->actingAs($operator)->get("/tasks/{$task->id}")->assertOk()->assertSee('Tool calls')->assertSee('execute_shell')->assertSee('Approval #');
    }

    public function test_input_summaries_redact_secrets(): void
    {
        $this->grant('create_lead');

        $execution = $this->useTool('create_lead', ['company_name' => 'X', 'source_reference' => 'y', 'api_key' => 'sk-live-123']);

        $this->assertSame('[redacted]', $execution->input_summary['api_key']);
        $this->assertStringNotContainsString('sk-live-123', (string) DB::table('tool_executions')->value('input_summary'));
    }
}
