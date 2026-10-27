<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_name' => fake()->company(),
            'primary_contact' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+233 20 000 0001',
            'status' => 'active',
        ];
    }
}
