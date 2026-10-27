<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Osu Dental',
            'primary_contact' => 'Dr. Owusu',
            'email' => 'Front@OsuDental.example',
            'phone' => '+233 30 000 0000',
            'status' => 'active',
            'billing_details' => 'Bank: Example Bank, Acct 0001234567',
        ], $overrides);
    }

    public function test_founder_creates_customer_and_billing_is_encrypted_at_rest(): void
    {
        $founder = User::factory()->founder()->create();

        $this->actingAs($founder)->post('/customers', $this->payload())->assertRedirect();

        $customer = Customer::query()->sole();
        $this->assertSame('front@osudental.example', $customer->email);
        $this->assertSame('Bank: Example Bank, Acct 0001234567', $customer->billing_details);

        $raw = DB::table('customers')->where('id', $customer->id)->value('billing_details');
        $this->assertStringNotContainsString('0001234567', $raw);

        $this->actingAs($founder)->get("/customers/{$customer->id}")->assertSee('0001234567');
    }

    public function test_operator_cannot_see_or_set_billing_details(): void
    {
        $operator = User::factory()->operator()->create();
        $customer = Customer::factory()->create();
        $customer->billing_details = 'SECRET-ACCOUNT-999';
        $customer->save();

        $this->actingAs($operator)->get("/customers/{$customer->id}")->assertOk()->assertDontSee('SECRET-ACCOUNT-999');
        $this->actingAs($operator)->get("/customers/{$customer->id}/edit")->assertOk()->assertDontSee('SECRET-ACCOUNT-999');

        $this->actingAs($operator)->put("/customers/{$customer->id}", $this->payload(['billing_details' => 'overwritten']))->assertRedirect();

        $fresh = $customer->fresh();
        $this->assertSame('SECRET-ACCOUNT-999', $fresh->billing_details);
        $this->assertSame('Osu Dental', $fresh->company_name);
    }

    public function test_billing_details_never_reach_audit_logs_or_serialization(): void
    {
        $founder = User::factory()->founder()->create();
        $this->actingAs($founder)->post('/customers', $this->payload());
        $customer = Customer::query()->sole();
        $this->actingAs($founder)->put("/customers/{$customer->id}", $this->payload(['billing_details' => 'NEW-ACCT-42']));

        $this->assertStringNotContainsString('NEW-ACCT-42', AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString('0001234567', AuditLog::query()->get()->toJson());
        $this->assertArrayNotHasKey('billing_details', $customer->fresh()->toArray());
        $this->assertTrue(AuditLog::query()->where('action', 'customer.updated')->sole()->metadata['billing_changed']);
    }

    public function test_viewer_cannot_create_or_edit_customers(): void
    {
        $viewer = User::factory()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($viewer)->get('/customers')->assertOk();
        $this->actingAs($viewer)->get('/customers/create')->assertForbidden();
        $this->actingAs($viewer)->post('/customers', $this->payload())->assertForbidden();
        $this->actingAs($viewer)->put("/customers/{$customer->id}", $this->payload())->assertForbidden();
    }

    public function test_invalid_customer_is_rejected(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->post('/customers', $this->payload(['company_name' => '', 'email' => 'nope', 'status' => 'vip']))
            ->assertSessionHasErrors(['company_name', 'email', 'status']);
    }

    public function test_index_search_and_missing_records(): void
    {
        $user = User::factory()->create();
        Customer::factory()->create(['company_name' => 'Labadi Gym']);
        Customer::factory()->create(['company_name' => 'Other Co']);

        $this->actingAs($user)->get('/customers?q=labadi')->assertSee('Labadi Gym')->assertDontSee('Other Co');
        $this->actingAs($user)->get('/customers/999999')->assertNotFound();
    }
}
