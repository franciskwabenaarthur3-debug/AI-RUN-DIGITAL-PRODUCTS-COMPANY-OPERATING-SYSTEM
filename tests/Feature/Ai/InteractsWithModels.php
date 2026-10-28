<?php

namespace Tests\Feature\Ai;

use App\Agents\Runtime\LlmRuntime;
use App\Agents\Runtime\MockRuntime;
use App\Agents\Runtime\RuntimeRegistry;
use App\Ai\ModelCatalog;
use App\Ai\Providers\AnthropicProvider;
use App\Enums\AgentRole;
use App\Models\Agent;
use App\Models\CompanySetting;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;

trait InteractsWithModels
{
    protected FakeAnthropic $fake;

    protected User $operator;

    protected function setUpModels(): void
    {
        $this->fake = new FakeAnthropic;
        $this->operator = User::factory()->operator()->create();
        $this->app->instance(RuntimeRegistry::class, new RuntimeRegistry([
            'mock' => new MockRuntime,
            'llm' => new LlmRuntime(app(ModelCatalog::class), ['anthropic' => new AnthropicProvider('sk-test', null, 0, $this->fake)]),
        ]));
        CompanySetting::current()->forceFill(['monthly_ai_budget_minor' => 10_000])->save();
    }

    protected function llmAgent(AgentRole $role, array $tools = [], array $attrs = []): Agent
    {
        $agent = Agent::factory()->role($role)->enabled()->create(array_merge([
            'runtime' => 'llm',
            'model_key' => 'claude-opus-5-5',
            'monthly_budget_minor' => 5_000,
            'max_tokens_per_run' => 50_000,
            'max_steps' => 6,
            'review_required' => true,
        ], $attrs));

        foreach ($tools as $tool) {
            $agent->permissions()->create(['tool_name' => $tool, 'resource_scope' => '*', 'allowed' => true]);
        }

        return $agent;
    }

    protected function runTask(Agent $agent, array $input = [], string $title = 'Find bakeries in Accra'): Task
    {
        $tasks = app(TaskService::class);
        $task = $tasks->create(['title' => $title, 'description' => 'Look for small bakeries with outdated websites.', 'agent_id' => $agent->id, 'input' => $input], $this->operator);
        $tasks->queue($task, $this->operator);

        return $task->fresh();
    }
}
