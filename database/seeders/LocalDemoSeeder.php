<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local development data only. Package prices here are placeholders, not
 * real pricing; real packages must be entered by the founder.
 *
 *   php artisan db:seed --class=LocalDemoSeeder
 */
class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalDemoSeeder may only run in local or testing environments.');
        }

        $this->call(DatabaseSeeder::class);

        $founder = User::query()->firstOrNew(['email' => 'founder@example.test']);
        $founder->fill(['name' => 'Local Founder', 'password' => 'local-password-123']);
        $founder->role = UserRole::Founder;
        $founder->is_active = true;
        $founder->save();

        $packages = [
            ['name' => 'Landing Page (placeholder)', 'slug' => 'landing-page', 'days' => 7, 'price' => 0],
            ['name' => 'Business Website (placeholder)', 'slug' => 'business-website', 'days' => 21, 'price' => 0],
            ['name' => 'Monthly Maintenance (placeholder)', 'slug' => 'monthly-maintenance', 'days' => 1, 'price' => 0],
        ];

        foreach ($packages as $p) {
            ServicePackage::query()->updateOrCreate(['slug' => $p['slug']], [
                'name' => $p['name'],
                'description' => 'Placeholder package for local development. Set real scope and pricing before use.',
                'scope' => ['To be defined by the founder'],
                'exclusions' => ['To be defined by the founder'],
                'base_price_minor' => $p['price'],
                'currency' => 'USD',
                'estimated_days' => $p['days'],
                'revision_limit' => 2,
                'is_active' => false,
            ]);
        }
    }
}
