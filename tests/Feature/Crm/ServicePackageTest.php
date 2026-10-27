<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePackageTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Business Website',
            'slug' => '',
            'description' => 'Five-page responsive site.',
            'scope' => "Home page\nAbout page\n\n  Contact form  \n",
            'exclusions' => "Copywriting\nHosting fees",
            'base_price' => '1250.50',
            'currency' => 'GHS',
            'estimated_days' => 21,
            'revision_limit' => 2,
            'is_active' => '1',
        ], $overrides);
    }

    public function test_founder_creates_package_with_minor_unit_price_and_clean_lists(): void
    {
        $this->actingAs(User::factory()->founder()->create())
            ->post('/service-packages', $this->payload())
            ->assertRedirect('/service-packages');

        $package = ServicePackage::query()->sole();
        $this->assertSame('business-website', $package->slug);
        $this->assertSame(125050, $package->base_price_minor);
        $this->assertSame('GHS', $package->currency);
        $this->assertSame(['Home page', 'About page', 'Contact form'], $package->scope);
        $this->assertSame(['Copywriting', 'Hosting fees'], $package->exclusions);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service_package.created', 'resource_id' => $package->id]);
    }

    public function test_price_change_is_audited_with_old_and_new_values(): void
    {
        $founder = User::factory()->founder()->create();
        $this->actingAs($founder)->post('/service-packages', $this->payload());
        $package = ServicePackage::query()->sole();

        $this->actingAs($founder)->put("/service-packages/{$package->id}", $this->payload(['slug' => 'business-website', 'base_price' => '1400']));

        $this->assertSame(140000, $package->fresh()->base_price_minor);
        $log = AuditLog::query()->where('action', 'service_package.updated')->sole();
        $this->assertSame(['from' => 125050, 'to' => 140000], $log->metadata['changes']['base_price_minor']);
    }

    public function test_package_can_be_deactivated(): void
    {
        $founder = User::factory()->founder()->create();
        $package = ServicePackage::factory()->create(['slug' => 'pkg']);

        $this->actingAs($founder)->put("/service-packages/{$package->id}", $this->payload(['slug' => 'pkg', 'is_active' => '0']));

        $this->assertFalse($package->fresh()->is_active);
    }

    public function test_operators_and_viewers_cannot_change_packages(): void
    {
        $package = ServicePackage::factory()->create();

        foreach ([User::factory()->operator()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->get('/service-packages')->assertOk()->assertDontSee('New package');
            $this->actingAs($user)->get('/service-packages/create')->assertForbidden();
            $this->actingAs($user)->post('/service-packages', $this->payload())->assertForbidden();
            $this->actingAs($user)->put("/service-packages/{$package->id}", $this->payload(['base_price' => '1']))->assertForbidden();
        }

        $this->assertDatabaseCount('service_packages', 1);
        $this->assertNotSame(100, $package->fresh()->base_price_minor);
    }

    public function test_invalid_package_is_rejected(): void
    {
        ServicePackage::factory()->create(['slug' => 'taken']);

        $this->actingAs(User::factory()->founder()->create())
            ->post('/service-packages', $this->payload([
                'slug' => 'taken',
                'scope' => '',
                'base_price' => '-10',
                'currency' => 'BTC',
                'estimated_days' => 0,
                'revision_limit' => 99,
            ]))
            ->assertSessionHasErrors(['slug', 'scope', 'base_price', 'currency', 'estimated_days', 'revision_limit']);
    }

    public function test_price_with_three_decimals_is_rejected(): void
    {
        $this->actingAs(User::factory()->founder()->create())
            ->post('/service-packages', $this->payload(['base_price' => '10.005']))
            ->assertSessionHasErrors('base_price');
    }
}
