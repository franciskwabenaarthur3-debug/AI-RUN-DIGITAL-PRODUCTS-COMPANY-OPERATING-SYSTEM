<?php

namespace Tests\Feature\Agents;

use App\Enums\AgentStatus;
use App\Models\Agent;
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
