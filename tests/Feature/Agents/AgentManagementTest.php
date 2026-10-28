<?php

namespace Tests\Feature\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\AgentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_registers_seven_disabled_agents_and_is_idempotent(): void
    {
        $this->seed(AgentSeeder::class);
        $sales = Agent::query()->where('role', 'sales')->sole();
        $sales->forceFill(['status' => 'enabled'])->save();
        $sales->permissions()->where('tool_name', 'draft_outreach')->update(['allowed' => false]);

        $this->seed(AgentSeeder::class);

        $this->assertSame(7, Agent::query()->count());
        $this->assertSame(6, Agent::query()->where('status', 'disabled')->count());
        $this->assertTrue($sales->fresh()->isEnabled(), 'Re-seeding must not reset founder changes.');
        $this->assertFalse($sales->mayUseTool('draft_outreach'));
    }

    public function test_tool_permissions_are_enforced(): void
    {
        $this->seed(AgentSeeder::class);
        $research = Agent::query()->where('role', 'research')->sole();

        $this->assertTrue($research->mayUseTool('analyze_website'));
        $this->assertFalse($research->mayUseTool('draft_proposal'), 'Not granted.');
        $this->assertFalse($research->mayUseTool('run_shell'), 'Unknown tools are denied.');
        $this->assertFalse(Agent::query()->where('role', 'finance')->sole()->mayUseTool('request_approval'));
    }

    public function test_founder_enables_agent_with_confirmation_and_it_is_audited(): void
    {
        $founder = User::factory()->founder()->create();
        $agent = Agent::factory()->create();

        $this->actingAs($founder)->put("/agents/{$agent->id}/status", ['status' => 'enabled'])->assertSessionHasErrors('confirm');
        $this->assertFalse($agent->fresh()->isEnabled());

        $this->actingAs($founder)->put("/agents/{$agent->id}/status", ['status' => 'enabled', 'confirm' => '1']);
        $this->assertSame(AgentStatus::Enabled, $agent->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.status_changed', 'resource_id' => $agent->id, 'actor_id' => $founder->id]);
    }

    public function test_founder_changes_permissions_only_from_the_catalog(): void
    {
        $founder = User::factory()->founder()->create();
        $agent = Agent::factory()->create();

        $this->actingAs($founder)->put("/agents/{$agent->id}/permissions", ['tools' => ['analyze_website', 'create_lead']])->assertSessionHas('status');
        $this->assertTrue($agent->mayUseTool('create_lead'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.permission_granted']);

        $this->actingAs($founder)->put("/agents/{$agent->id}/permissions", ['tools' => ['analyze_website']]);
        $this->assertFalse($agent->mayUseTool('create_lead'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'agent.permission_revoked']);

        $this->actingAs($founder)->put("/agents/{$agent->id}/permissions", ['tools' => ['execute_shell']])->assertSessionHasErrors('tools.0');
        $this->assertDatabaseMissing('agent_permissions', ['tool_name' => 'execute_shell']);
    }

    private const CONFIG = [
        'runtime' => 'llm', 'model_key' => 'claude-sonnet-5-5', 'max_steps' => 8, 'max_retries' => 1,
        'timeout_seconds' => 300, 'max_tokens_per_run' => 80000, 'max_tool_calls_per_run' => 12, 'monthly_budget' => '25.50',
    ];

    public function test_founder_configures_runtime_model_and_limits_and_it_is_audited(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        $founder = User::factory()->founder()->create();
        $agent = Agent::factory()->create(['max_steps' => 4]);

        $this->actingAs($founder)->put("/agents/{$agent->id}/configuration", ['model_key' => ''] + self::CONFIG)->assertSessionHasErrors('model_key');
        $this->actingAs($founder)->put("/agents/{$agent->id}/configuration", ['model_key' => 'gpt-9'] + self::CONFIG)->assertSessionHasErrors('model_key');
        $this->actingAs($founder)->put("/agents/{$agent->id}/configuration", ['max_steps' => 500] + self::CONFIG)->assertSessionHasErrors('max_steps');

        $this->actingAs($founder)->put("/agents/{$agent->id}/configuration", self::CONFIG)->assertSessionHas('status');

        $agent->refresh();
        $this->assertSame(['llm', 'claude-sonnet-5-5', 8, 2550], [$agent->runtime, $agent->model_key, $agent->max_steps, $agent->monthly_budget_minor]);
        $changes = AuditLog::query()->where('action', 'agent.configured')->sole()->metadata['changes'];
        $this->assertSame(['from' => 4, 'to' => 8], $changes['max_steps']);

        $this->actingAs($founder)->put("/agents/{$agent->id}/configuration", self::CONFIG);
        $this->assertSame(1, AuditLog::query()->where('action', 'agent.configured')->count(), 'Unchanged saves are not audited.');
    }

    public function test_model_runtime_cannot_be_chosen_without_an_api_key(): void
    {
        config(['services.anthropic.key' => null]);
        $agent = Agent::factory()->create();

        $this->actingAs(User::factory()->founder()->create())->put("/agents/{$agent->id}/configuration", self::CONFIG)->assertSessionHasErrors('runtime');
        $this->assertSame('mock', $agent->fresh()->runtime);
    }

    public function test_only_founders_can_configure_agents(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        $agent = Agent::factory()->create();

        $this->actingAs(User::factory()->operator()->create())->put("/agents/{$agent->id}/configuration", self::CONFIG)->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'agent.configured')->count());
    }

    public function test_operators_and_viewers_cannot_change_agents(): void
    {
        $agent = Agent::factory()->create();

        foreach ([User::factory()->operator()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get('/agents')->assertOk();
            $this->actingAs($user)->get("/agents/{$agent->id}")->assertOk()->assertDontSee('Save permissions');
            $this->actingAs($user)->put("/agents/{$agent->id}/status", ['status' => 'enabled', 'confirm' => '1'])->assertForbidden();
            $this->actingAs($user)->put("/agents/{$agent->id}/permissions", ['tools' => ['create_lead']])->assertForbidden();
        }

        $this->assertFalse($agent->fresh()->isEnabled());
        $this->assertDatabaseCount('agent_permissions', 0);
    }

    public function test_agent_pages_render(): void
    {
        $this->seed(AgentSeeder::class);
        $user = User::factory()->founder()->create();

        $this->actingAs($user)->get('/agents')->assertOk()->assertSee('Research Agent')->assertSee('Disabled');
        $agent = Agent::query()->first();
        $this->actingAs($user)->get("/agents/{$agent->id}")->assertOk()->assertSee('mock runtime')->assertSee('Save permissions');
    }
}
