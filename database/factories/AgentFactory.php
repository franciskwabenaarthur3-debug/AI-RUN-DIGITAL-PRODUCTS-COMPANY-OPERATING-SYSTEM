<?php

namespace Database\Factories;

use App\Enums\AgentRole;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        $role = fake()->unique()->randomElement(AgentRole::cases());

        return [
            'name' => $role->label().' Agent',
            'slug' => str_replace('_', '-', $role->value),
            'role' => $role,
            'description' => 'Test agent',
            'instructions_version' => 'v1',
            'runtime' => 'mock',
            'model_key' => 'mock-1',
            'max_steps' => 5,
            'max_retries' => 2,
            'timeout_seconds' => 60,
            'max_tokens_per_run' => 5000,
            'monthly_budget_minor' => 0,
            'review_required' => true,
        ];
    }

    public function role(AgentRole $role): static
    {
        return $this->state(fn () => ['role' => $role, 'slug' => str_replace('_', '-', $role->value), 'name' => $role->label().' Agent']);
    }

    public function enabled(): static
    {
        return $this->afterMaking(fn (Agent $a) => $a->status = 'enabled');
    }
}
