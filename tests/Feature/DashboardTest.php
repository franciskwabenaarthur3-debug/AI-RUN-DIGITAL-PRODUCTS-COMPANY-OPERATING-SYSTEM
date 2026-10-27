<?php

namespace Tests\Feature;

use App\Models\ServicePackage;
use App\Models\User;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_shows_onboarding_and_no_invented_figures(): void
    {
        $this->actingAs(User::factory()->founder()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Getting started')
            ->assertSee('USD 0.00')
            ->assertSeeInOrder(['Active service packages', '0']);
    }

    public function test_counts_only_active_service_packages(): void
    {
        ServicePackage::factory()->count(2)->create();
        ServicePackage::factory()->inactive()->create();

        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $this->assertSame(2, $response->viewData('activePackages'));
        $this->assertNull($response->viewData('recentAudit'), 'Viewers must not receive audit data.');
    }

    public function test_service_package_prices_are_integer_minor_units(): void
    {
        $package = ServicePackage::factory()->create(['base_price_minor' => 49999, 'scope' => ['Home', 'About']]);

        $fresh = $package->fresh();
        $this->assertSame(49999, $fresh->base_price_minor);
        $this->assertSame(['Home', 'About'], $fresh->scope);
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->app->make(LocalDemoSeeder::class)->run();
    }
}
