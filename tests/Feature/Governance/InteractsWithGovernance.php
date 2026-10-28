<?php

namespace Tests\Feature\Governance;

use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolGateway;
use App\Agents\Tools\ToolRegistry;
use App\Enums\AgentRole;
use App\Enums\AgentRunStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

trait InteractsWithGovernance
{
    protected User $founder;

    protected Agent $agent;

    protected FakeReleaseTool $release;

    protected function setUpGovernance(): void
    {
        $this->founder = User::factory()->founder()->create();
        $this->agent = Agent::factory()->role(AgentRole::Orchestrator)->enabled()->create();
        $this->release = new FakeReleaseTool;
        app(ToolRegistry::class)->register($this->release);
    }

    protected function grant(string ...$tools): void
    {
        foreach ($tools as $tool) {
            $this->agent->permissions()->updateOrCreate(['tool_name' => $tool, 'resource_scope' => '*'], ['allowed' => true]);
        }
    }

    protected function agentRun(?Task $task = null): AgentRun
    {
        $task ??= Task::factory()->create(['agent_id' => $this->agent->id]);

        return AgentRun::query()->create([
            'task_id' => $task->id,
            'agent_id' => $this->agent->id,
            'attempt' => $task->runs()->count() + 1,
            'status' => AgentRunStatus::Running,
            'runtime' => 'mock',
            'instructions_version' => 'v1',
            'started_at' => now(),
            'deadline_at' => now()->addMinute(),
            'input_hash' => str_repeat('0', 64),
            'correlation_id' => (string) Str::uuid(),
        ]);
    }

    protected function context(?AgentRun $run = null): ToolContext
    {
        $run ??= $this->agentRun();

        return new ToolContext($this->agent, $run, $run->task);
    }

    protected function useTool(string $tool, array $input, ?ToolContext $context = null)
    {
        return app(ToolGateway::class)->call($context ?? $this->context(), $tool, $input);
    }
}
