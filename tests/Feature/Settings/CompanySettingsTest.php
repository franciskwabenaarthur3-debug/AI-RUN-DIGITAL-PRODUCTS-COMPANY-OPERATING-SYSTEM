<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Arthur Digital',
            'default_currency' => 'GHS',
            'timezone' => 'Africa/Accra',
            'approval_policy' => 'strict',
            'monthly_ai_budget' => '150.50',
            'operating_status' => 'active',
        ], $overrides);
    }

    public function test_any_active_user_can_view_settings(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings')
            ->assertOk()
            ->assertSee('Only the founder can change these settings.')
            ->assertDontSee('Save settings');
    }

    public function test_founder_can_update_settings_and_budget_is_stored_in_minor_units(): void
    {
        $founder = User::factory()->founder()->create();

        $this->actingAs($founder)->put('/settings', $this->validPayload())
            ->assertRedirect('/settings')
            ->assertSessionHas('status');

        $settings = CompanySetting::current();
        $this->assertSame('Arthur Digital', $settings->company_name);
        $this->assertSame('GHS', $settings->default_currency);
        $this->assertSame(15050, $settings->monthly_ai_budget_minor);
        $this->assertSame(1, CompanySetting::query()->count());
    }

    public function test_settings_change_is_audited_with_before_and_after_values(): void
    {
        $founder = User::factory()->founder()->create();
        CompanySetting::current();

        $this->actingAs($founder)->put('/settings', $this->validPayload());

        $log = AuditLog::query()->where('action', 'settings.updated')->sole();
        $this->assertSame($founder->id, $log->actor_id);
        $this->assertSame(['from' => 0, 'to' => 15050], $log->metadata['changes']['monthly_ai_budget_minor']);
        $this->assertNotNull($log->correlation_id);
    }

    public function test_unchanged_settings_do_not_create_audit_records(): void
    {
        $founder = User::factory()->founder()->create();
        $this->actingAs($founder)->put('/settings', $this->validPayload());
        $this->actingAs($founder)->put('/settings', $this->validPayload());

        $this->assertSame(1, AuditLog::query()->where('action', 'settings.updated')->count());
    }

    public function test_non_founders_cannot_update_settings_by_direct_request(): void
    {
        foreach ([User::factory()->operator()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)->put('/settings', $this->validPayload())->assertForbidden();
        }

        $this->assertNotSame('Arthur Digital', CompanySetting::current()->company_name);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $founder = User::factory()->founder()->create();

        $this->actingAs($founder)->put('/settings', $this->validPayload([
            'company_name' => '',
            'default_currency' => 'XYZ',
            'timezone' => 'Mars/Olympus',
            'approval_policy' => 'none',
            'monthly_ai_budget' => '-5',
            'operating_status' => 'chaos',
        ]))->assertSessionHasErrors([
            'company_name', 'default_currency', 'timezone', 'approval_policy', 'monthly_ai_budget', 'operating_status',
        ]);
    }

    public function test_budget_with_more_than_two_decimals_is_rejected(): void
    {
        $this->actingAs(User::factory()->founder()->create())
            ->put('/settings', $this->validPayload(['monthly_ai_budget' => '10.999']))
            ->assertSessionHasErrors('monthly_ai_budget');
    }

    public function test_pausing_operations_shows_banner(): void
    {
        $founder = User::factory()->founder()->create();

        $this->actingAs($founder)->put('/settings', $this->validPayload(['operating_status' => 'paused']));

        $this->assertTrue(CompanySetting::current()->isPaused());
        $this->actingAs($founder)->get('/dashboard')->assertSee('Emergency pause is on');
    }
}
