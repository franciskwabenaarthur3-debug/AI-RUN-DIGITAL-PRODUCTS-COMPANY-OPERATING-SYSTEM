<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

/**
 * Safe for every environment: creates the company settings row and the
 * (disabled) agent registry.
 * Founder accounts are created with `php artisan company-os:create-user`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        CompanySetting::current();
        $this->call(AgentSeeder::class);
    }
}
