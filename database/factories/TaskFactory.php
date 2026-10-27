<?php

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'priority' => 'normal',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Task $t) {
            $t->created_by_type ??= 'user';
        });
    }

    public function status(TaskStatus $status): static
    {
        return $this->afterMaking(fn (Task $t) => $t->status = $status);
    }
}
