<?php

namespace Database\Factories;

use App\Models\ServicePackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServicePackage>
 */
class ServicePackageFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'scope' => ['Design', 'Build', 'Launch'],
            'exclusions' => ['Copywriting'],
            'base_price_minor' => fake()->numberBetween(10_000, 500_000),
            'currency' => 'USD',
            'estimated_days' => fake()->numberBetween(5, 30),
            'revision_limit' => 2,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
