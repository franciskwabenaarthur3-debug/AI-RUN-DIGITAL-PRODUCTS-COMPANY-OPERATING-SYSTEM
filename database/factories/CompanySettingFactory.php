<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySetting>
 */
class CompanySettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_name' => fake()->company(),
            'default_currency' => 'USD',
            'timezone' => 'UTC',
            'approval_policy' => 'strict',
            'monthly_ai_budget_minor' => 5000,
            'operating_status' => 'active',
        ];
    }
}
