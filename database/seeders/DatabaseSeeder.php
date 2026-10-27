<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use Illuminate\Database\Seeder;

/**
 * Safe for every environment: only creates the company settings row.
 * Founder accounts are created with `php artisan company-os:create-user`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        CompanySetting::current();
    }
}
