<?php

namespace Tests\Feature\Crm;

use App\Enums\LeadStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->operator()->create();
    }

    public function test_valid_transition_is_applied_and_recorded(): void
    {
        $lead = Lead::factory()->create();

        $this->actingAs($this->operator)
            ->post("/leads/{$lead->id}/status", ['status' => 'researched', 'reason' => 'Site reviewed'])
            ->assertSessionHas('status');

        $this->assertSame(LeadStatus::Researched, $lead->fresh()->status);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'activity_type' => 'status_changed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.status_changed', 'resource_id' => $lead->id]);
    }

    public function test_invalid_transition_is_refused(): void
    {
        $lead = Lead::factory()->create();

        $this->actingAs($this->operator)
            ->post("/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertSessionHasErrors('status');

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
        $this->assertDatabaseMissing('lead_activities', ['activity_type' => 'status_changed']);
    }

    public function test_converted_and_unsubscribed_cannot_be_set_directly(): void
    {
        $lead = Lead::factory()->status(LeadStatus::Replied)->create();

        $this->actingAs($this->operator)->post("/leads/{$lead->id}/status", ['status' => 'converted'])->assertSessionHasErrors('status');
        $this->actingAs($this->operator)->post("/leads/{$lead->id}/status", ['status' => 'unsubscribed'])->assertSessionHasErrors('status');

        $this->assertSame(LeadStatus::Replied, $lead->fresh()->status);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_opt_out_marks_lead_do_not_contact_and_requires_confirmation(): void
    {
        $lead = Lead::factory()->status(LeadStatus::Qualified)->create(['next_follow_up_at' => now()->addDay()]);

        $this->actingAs($this->operator)
            ->post("/leads/{$lead->id}/opt-out", ['opt_out_reason' => 'Asked to be removed'])
            ->assertSessionHasErrors('confirm');
        $this->assertFalse($lead->fresh()->do_not_contact);

        $this->actingAs($this->operator)
            ->post("/leads/{$lead->id}/opt-out", ['opt_out_reason' => 'Asked to be removed', 'confirm' => '1'])
            ->assertSessionHas('status');

        $fresh = $lead->fresh();
        $this->assertTrue($fresh->do_not_contact);
        $this->assertSame(LeadStatus::Unsubscribed, $fresh->status);
        $this->assertNotNull($fresh->opted_out_at);
        $this->assertNull($fresh->next_follow_up_at);
        $this->assertFalse($fresh->canBeContacted());
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.opted_out', 'resource_id' => $lead->id]);
    }

    public function test_unsubscribed_contact_cannot_enter_outreach(): void
    {
        $lead = Lead::factory()->optedOut()->create();

        $this->actingAs($this->operator)
            ->post("/leads/{$lead->id}/status", ['status' => 'outreach_drafted'])
            ->assertSessionHasErrors('status');

        $this->assertSame(LeadStatus::Unsubscribed, $lead->fresh()->status);
    }

    public function test_do_not_contact_flag_blocks_outreach_even_if_status_allows_it(): void
    {
        // Defense in depth: a lead flagged do-not-contact but still "qualified"
        // (e.g. a future import path) must still be blocked.
        $lead = Lead::factory()->status(LeadStatus::Qualified)->create();
        $lead->forceFill(['do_not_contact' => true])->save();

        $this->expectException(InvalidTransition::class);
        app(LeadService::class)->changeStatus($lead, LeadStatus::OutreachDrafted, $this->operator);
    }

    public function test_an_opted_out_lead_cannot_be_reused_by_creating_a_new_lead(): void
    {
        Lead::factory()->optedOut()->create(['email' => 'stop@example.com']);

        $this->actingAs($this->operator)
            ->post('/leads', ['company_name' => 'Same Person', 'email' => 'stop@example.com', 'source' => 'manual', 'research_status' => 'pending'])
            ->assertSessionHasErrors('email');
    }

    public function test_conversion_creates_exactly_one_customer(): void
    {
        $lead = Lead::factory()->status(LeadStatus::Replied)->create(['company_name' => 'Cape Coast Hotel']);

        $first = $this->actingAs($this->operator)->post("/leads/{$lead->id}/convert", ['confirm' => '1']);
        $customer = Customer::query()->sole();
        $first->assertRedirect(route('customers.show', $customer));

        // Repeated submissions (double click, retry) do not create another.
        $this->actingAs($this->operator)->post("/leads/{$lead->id}/convert", ['confirm' => '1'])
            ->assertRedirect(route('customers.show', $customer));
        $again = app(LeadService::class)->convert($lead->fresh(), $this->operator);

        $this->assertSame($customer->id, $again->id);
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('Cape Coast Hotel', $customer->company_name);

        $fresh = $lead->fresh();
        $this->assertSame(LeadStatus::Converted, $fresh->status);
        $this->assertSame($customer->id, $fresh->customer_id);
        $this->assertSame(1, $fresh->activities()->where('activity_type', 'converted')->count());
    }

    public function test_conversion_from_stale_model_instance_is_still_single(): void
    {
        $lead = Lead::factory()->status(LeadStatus::Contacted)->create();
        $staleA = Lead::query()->find($lead->id);
        $staleB = Lead::query()->find($lead->id);

        $a = app(LeadService::class)->convert($staleA, $this->operator);
        $b = app(LeadService::class)->convert($staleB, $this->operator);

        $this->assertSame($a->id, $b->id);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_conversion_requires_confirmation_and_an_eligible_status(): void
    {
        $lead = Lead::factory()->create();

        $this->actingAs($this->operator)->post("/leads/{$lead->id}/convert")->assertSessionHasErrors('confirm');
        $this->actingAs($this->operator)->post("/leads/{$lead->id}/convert", ['confirm' => '1'])->assertSessionHasErrors('convert');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_unsubscribed_lead_cannot_be_converted(): void
    {
        $lead = Lead::factory()->optedOut()->create();

        $this->actingAs($this->operator)->post("/leads/{$lead->id}/convert", ['confirm' => '1'])->assertSessionHasErrors('convert');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_lead_activities_are_immutable(): void
    {
        $lead = Lead::factory()->create();
        $activity = app(LeadService::class)->addNote($lead, 'original', $this->operator);

        $this->expectException(\LogicException::class);
        $activity->update(['description' => 'tampered']);
    }
}
