<?php

namespace Tests\Feature\Agents;

use App\Agents\Runtime\AgentRuntime;
use App\Agents\Runtime\RunRequest;
use App\Agents\Runtime\RunResult;
use App\Agents\Runtime\RuntimeRegistry;
use App\Agents\Runtime\UsageRecord;
use App\Enums\AgentRole;
use App\Enums\AgentRunStatus;
use App\Enums\TaskStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\CompanySetting;
use App\Models\ModelUsage;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskRunner;
use App\Services\TaskService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskExecutionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->operator()->create();
        $this->agent = Agent::factory()->role(AgentRole::Sales)->enabled()->create();
    }

    private function task(array $input = [], array $attrs = []): Task
    {
        return app(TaskService::class)->create(array_merge(['title' => 'Draft follow-up email', 'agent_id' => $this->agent->id, 'input' => $input], $attrs), $this->operator);
    }

    private function queue(Task $task): Task
    {
        app(TaskService::class)->queue($task, $this->operator);

        return $task->fresh();
    }

    public function test_successful_run_records_output_usage_and_awaits_review(): void
    {
        $task = $this->queue($this->task());

        $this->assertSame(TaskStatus::AwaitingReview, $task->status);
        $this->assertSame('draft', $task->result['kind']);
        $this->assertStringContainsString('[Mock output]', $task->result['summary']);

        $run = AgentRun::query()->sole();
        $this->assertSame(AgentRunStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->attempt);
        $this->assertSame('v1', $run->instructions_version);
        $this->assertNotNull($run->correlation_id);
        $this->assertSame(64, strlen($run->input_hash));
        $this->assertSame(150, ModelUsage::query()->sole()->input_tokens + ModelUsage::query()->sole()->output_tokens);
        $this->assertNull(ModelUsage::query()->sole()->actual_cost_minor, 'Actual cost is never guessed.');
    }

    public function test_agent_without_review_completes_directly(): void
    {
        $this->agent->update(['review_required' => false]);

        $task = $this->queue($this->task());

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_invalid_model_output_is_rejected(): void
    {
        $task = $this->queue($this->task(['mock_scenario' => 'invalid_output']));

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertNull($task->result);
        $this->assertStringContainsString('invalid_output', $task->failure_reason);
        $this->assertSame('invalid_output', AgentRun::query()->sole()->error_code);
        $this->assertSame(1, ModelUsage::query()->count(), 'Usage is recorded even for failed runs.');
    }

    public function test_agent_loops_terminate_at_the_step_limit(): void
    {
        $task = $this->queue($this->task(['mock_scenario' => 'loop']));

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('step_limit_exceeded', AgentRun::query()->sole()->error_code);
    }

    public function test_usage_cap_is_enforced(): void
    {
        $task = $this->queue($this->task(['mock_scenario' => 'token_heavy']));

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('usage_limit_exceeded', AgentRun::query()->sole()->error_code);
    }

    public function test_timeouts_are_retried_a_bounded_number_of_times_then_fail(): void
    {
        $task = $this->queue($this->task(['mock_scenario' => 'timeout']));

        // max_retries = 2 → 3 attempts in total, all recorded.
        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(3, $task->attempts);
        $this->assertSame(3, AgentRun::query()->where('error_code', 'timeout')->count());
        $this->assertStringContainsString('[timeout]', $task->failure_reason);
    }

    public function test_transient_error_recovers_on_retry(): void
    {
        $task = $this->queue($this->task(['mock_scenario' => 'flaky']));

        $this->assertSame(TaskStatus::AwaitingReview, $task->status);
        $this->assertSame(2, $task->attempts);
        $this->assertSame(['failed', 'succeeded'], AgentRun::query()->orderBy('attempt')->pluck('status')->map->value->all());
    }

    public function test_non_retryable_error_fails_immediately(): void
    {
        $task = $this->queue($this->task(['mock_scenario' => 'fatal']));

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertSame('provider_error', AgentRun::query()->sole()->error_code);
    }

    public function test_run_past_its_deadline_is_treated_as_timeout(): void
    {
        $this->agent->update(['max_retries' => 0]);
        $slow = new class implements AgentRuntime
        {
            public function name(): string
            {
                return 'slow';
            }

            public function isPaid(): bool
            {
                return false;
            }

            public function maxRunCostMinor(RunRequest $r): int
            {
                return 0;
            }

            public function run(RunRequest $r): RunResult
            {
                Carbon::setTestNow($r->deadline->addSecond());

                return new RunResult(['kind' => 'draft', 'summary' => 'late'], 1);
            }
        };
        app(RuntimeRegistry::class)->register($slow);
        $this->agent->forceFill(['runtime' => 'slow'])->save();

        $task = $this->queue($this->task());

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertSame('timeout', AgentRun::query()->sole()->error_code);
    }

    public function test_disabled_agent_cannot_start_new_work(): void
    {
        $this->agent->forceFill(['status' => 'disabled'])->save();
        $task = $this->task();

        $this->actingAs($this->operator)->post("/tasks/{$task->id}/queue")->assertSessionHasErrors('task');
        $this->assertSame(TaskStatus::Created, $task->fresh()->status);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_agent_disabled_after_queueing_is_blocked_by_the_runner(): void
    {
        $task = $this->task();
        $task->forceFill(['status' => TaskStatus::Queued])->save();
        $this->agent->forceFill(['status' => 'disabled'])->save();

        $this->assertSame(TaskRunner::SKIPPED, app(TaskRunner::class)->run($task->id));
        $this->assertSame(TaskStatus::Blocked, $task->fresh()->status);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_duplicate_execution_is_prevented(): void
    {
        $task = $this->queue($this->task());
        $this->assertSame(1, AgentRun::query()->count());

        // A duplicate queue delivery for the same task does nothing.
        $this->assertSame(TaskRunner::SKIPPED, app(TaskRunner::class)->run($task->id));
        $this->assertSame(1, AgentRun::query()->count());

        // And the database refuses a second run for the same attempt.
        $this->expectException(UniqueConstraintViolationException::class);
        AgentRun::query()->create(AgentRun::query()->sole()->only([
            'task_id', 'agent_id', 'attempt', 'status', 'runtime', 'instructions_version', 'started_at', 'deadline_at', 'input_hash',
        ]));
    }

    public function test_emergency_pause_holds_queued_work_and_resume_runs_it(): void
    {
        $settings = CompanySetting::current();
        $settings->forceFill(['operating_status' => 'paused'])->save();

        $task = $this->queue($this->task());
        $this->assertSame(TaskStatus::Queued, $task->status);
        $this->assertDatabaseCount('agent_runs', 0);

        $founder = User::factory()->founder()->create();
        $this->actingAs($founder)->put('/settings', [
            'company_name' => $settings->company_name, 'default_currency' => 'USD', 'timezone' => 'UTC',
            'approval_policy' => 'strict', 'monthly_ai_budget' => '0', 'operating_status' => 'active',
        ]);

        $this->assertSame(TaskStatus::AwaitingReview, $task->fresh()->status);
    }

    public function test_mock_runtime_is_unavailable_when_disabled_by_config(): void
    {
        config(['company_os.agents.allow_mock_runtime' => false]);

        $task = $this->queue($this->task());

        $this->assertSame(TaskStatus::Blocked, $task->status);
        $this->assertStringContainsString('not available', $task->failure_reason);
    }

    public function test_paid_runtime_respects_company_and_agent_budgets(): void
    {
        $paid = new class implements AgentRuntime
        {
            public function name(): string
            {
                return 'paid';
            }

            public function isPaid(): bool
            {
                return true;
            }

            public function maxRunCostMinor(RunRequest $r): int
            {
                return 100;
            }

            public function run(RunRequest $r): RunResult
            {
                return new RunResult(['kind' => 'draft', 'summary' => 'ok'], 1, [new UsageRecord('test', 'm', 10, 10, 80, 'USD')]);
            }
        };
        app(RuntimeRegistry::class)->register($paid);
        $this->agent->forceFill(['runtime' => 'paid', 'monthly_budget_minor' => 1000])->save();

        // Company budget 0: hard stop.
        $task = $this->queue($this->task());
        $this->assertSame(TaskStatus::Blocked, $task->status);
        $this->assertStringContainsString('monthly AI budget', $task->failure_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.budget_blocked']);

        // Enough company budget: runs and records cost.
        CompanySetting::current()->forceFill(['monthly_ai_budget_minor' => 150])->save();
        app(TaskService::class)->retry($task->fresh(), $this->operator);
        $this->assertSame(TaskStatus::AwaitingReview, $task->fresh()->status);
        $this->assertSame(80, ModelUsage::companySpentThisMonthMinor());

        // 80 spent + 100 max for the next run > 150: blocked before spending.
        $second = $this->queue($this->task());
        $this->assertSame(TaskStatus::Blocked, $second->status);
        $this->assertSame(1, AgentRun::query()->count());

        // Agent budget is enforced separately.
        CompanySetting::current()->forceFill(['monthly_ai_budget_minor' => 100000])->save();
        $this->agent->forceFill(['monthly_budget_minor' => 150])->save();
        app(TaskService::class)->retry($second->fresh(), $this->operator);
        $this->assertStringContainsString('budget for agent', $second->fresh()->failure_reason);
    }

    public function test_dependencies_block_until_complete_then_release(): void
    {
        $first = $this->task([], ['title' => 'Research']);
        $second = app(TaskService::class)->create(['title' => 'Write', 'agent_id' => $this->agent->id], $this->operator, [$first->id]);

        $second = $this->queue($second);
        $this->assertSame(TaskStatus::Blocked, $second->status);

        $first = $this->queue($first);
        $this->assertSame(TaskStatus::AwaitingReview, $first->status);
        $this->assertSame(TaskStatus::Blocked, $second->fresh()->status);

        app(TaskService::class)->acceptReview($first, $this->operator);

        $this->assertSame(TaskStatus::AwaitingReview, $second->fresh()->status);
    }

    public function test_stale_run_from_a_crashed_worker_is_recovered(): void
    {
        $task = $this->task();
        $task->forceFill(['status' => TaskStatus::Running, 'attempts' => 1])->save();
        AgentRun::query()->create([
            'task_id' => $task->id, 'agent_id' => $this->agent->id, 'attempt' => 1, 'status' => 'running',
            'runtime' => 'mock', 'instructions_version' => 'v1', 'started_at' => now()->subHour(),
            'deadline_at' => now()->subMinutes(30), 'input_hash' => str_repeat('a', 64),
        ]);

        $this->artisan('company-os:recover-stale-runs')->assertSuccessful();

        // Lost run marked failed; the task was retried and (sync queue) ran again.
        $this->assertSame('worker_lost', AgentRun::query()->where('attempt', 1)->value('error_code'));
        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::AwaitingReview, $fresh->status);
        $this->assertSame(2, $fresh->attempts);
    }

    public function test_recovery_ignores_runs_still_within_their_deadline(): void
    {
        $task = $this->task();
        $task->forceFill(['status' => TaskStatus::Running, 'attempts' => 1])->save();
        AgentRun::query()->create([
            'task_id' => $task->id, 'agent_id' => $this->agent->id, 'attempt' => 1, 'status' => 'running',
            'runtime' => 'mock', 'instructions_version' => 'v1', 'started_at' => now(),
            'deadline_at' => now()->addMinutes(2), 'input_hash' => str_repeat('a', 64),
        ]);

        $this->artisan('company-os:recover-stale-runs')->assertSuccessful();

        $this->assertSame(TaskStatus::Running, $task->fresh()->status);
    }

    public function test_late_result_from_a_superseded_run_does_not_overwrite_state(): void
    {
        $task = $this->queue($this->task());
        $run = AgentRun::query()->sole();
        $run->forceFill(['status' => 'running'])->save();
        $task->forceFill(['status' => TaskStatus::Cancelled])->save();

        $method = new \ReflectionMethod(TaskRunner::class, 'fail');
        $outcome = $method->invoke(app(TaskRunner::class), $run, 'provider_error', 'late', true, []);

        $this->assertSame(TaskRunner::SKIPPED, $outcome);
        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
        $this->assertSame('superseded', $run->fresh()->error_code);
    }

    public function test_scheduler_registers_recovery(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('company-os:recover-stale-runs')->assertSuccessful();
    }
}
