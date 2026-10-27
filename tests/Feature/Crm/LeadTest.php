<?php

namespace Tests\Feature\Crm;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Accra Bakery',
            'contact_name' => 'Ama Mensah',
            'email' => 'Ama@AccraBakery.example',
            'phone' => '+233 20 123 4567',
            'website' => 'https://www.accrabakery.example/menu',
            'source' => 'referral',
            'source_reference' => 'Referred by Kofi, 2026-10-20',
            'research_status' => 'pending',
            'qualification_score' => 70,
            'notes' => 'Old site is not mobile friendly.',
        ], $overrides);
    }

    public function test_operator_can_create_lead_with_normalized_email_and_domain(): void
    {
        $operator = User::factory()->operator()->create();

        $response = $this->actingAs($operator)->post('/leads', $this->payload());

        $lead = Lead::query()->sole();
        $response->assertRedirect(route('leads.show', $lead));
        $this->assertSame('ama@accrabakery.example', $lead->email);
        $this->assertSame('accrabakery.example', $lead->website_domain);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame($operator->id, $lead->created_by);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'activity_type' => 'created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.created', 'resource_id' => $lead->id]);
    }

    public function test_invalid_lead_is_rejected(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->post('/leads', $this->payload([
                'company_name' => '',
                'email' => 'not-an-email',
                'website' => 'javascript:alert(1)',
                'phone' => 'call me',
                'source' => 'scraped',
                'qualification_score' => 150,
            ]))
            ->assertSessionHasErrors(['company_name', 'email', 'website', 'phone', 'source', 'qualification_score']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_duplicate_email_is_detected_case_insensitively(): void
    {
        $existing = Lead::factory()->create(['email' => 'owner@shop.example']);

        $this->actingAs(User::factory()->operator()->create())
            ->post('/leads', $this->payload(['email' => 'OWNER@shop.example', 'website' => null]))
            ->assertSessionHasErrors(['email' => "A lead with this email address already exists: {$existing->company_name} (#{$existing->id})."]);

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_duplicate_website_is_detected_after_normalization(): void
    {
        Lead::factory()->create(['website' => 'http://shop.example/']);

        $this->actingAs(User::factory()->operator()->create())
            ->post('/leads', $this->payload(['email' => null, 'website' => 'https://WWW.shop.example/contact']))
            ->assertSessionHasErrors('website');

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_database_rejects_duplicates_that_bypass_validation(): void
    {
        Lead::factory()->create(['email' => 'dup@example.com']);

        $this->expectException(UniqueConstraintViolationException::class);
        Lead::factory()->create(['email' => 'DUP@example.com']);
    }

    public function test_editing_a_lead_does_not_flag_itself_as_duplicate(): void
    {
        $lead = Lead::factory()->create(['email' => 'same@example.com', 'website' => 'same.example']);

        $this->actingAs(User::factory()->operator()->create())
            ->put("/leads/{$lead->id}", $this->payload(['email' => 'same@example.com', 'website' => 'https://same.example', 'company_name' => 'Renamed']))
            ->assertRedirect(route('leads.show', $lead));

        $this->assertSame('Renamed', $lead->fresh()->company_name);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'activity_type' => 'updated']);
    }

    public function test_status_cannot_be_set_through_the_edit_form(): void
    {
        $lead = Lead::factory()->create();

        $this->actingAs(User::factory()->founder()->create())
            ->put("/leads/{$lead->id}", $this->payload(['status' => 'converted', 'do_not_contact' => true, 'customer_id' => 99]));

        $fresh = $lead->fresh();
        $this->assertSame(LeadStatus::New, $fresh->status);
        $this->assertFalse($fresh->do_not_contact);
        $this->assertNull($fresh->customer_id);
    }

    public function test_index_searches_filters_and_paginates(): void
    {
        $user = User::factory()->create();
        Lead::factory()->create(['company_name' => 'Kumasi Florist']);
        Lead::factory()->status(LeadStatus::Qualified)->create(['company_name' => 'Tema Motors']);
        Lead::factory()->count(25)->create();

        $this->actingAs($user)->get('/leads?q=kumasi')->assertOk()->assertSee('Kumasi Florist')->assertDontSee('Tema Motors');
        $this->actingAs($user)->get('/leads?status=qualified')->assertOk()->assertSee('Tema Motors')->assertDontSee('Kumasi Florist');

        $page = $this->actingAs($user)->get('/leads')->viewData('leads');
        $this->assertSame(27, $page->total());
        $this->assertCount(20, $page->items());
    }

    public function test_search_treats_wildcards_literally(): void
    {
        Lead::factory()->create(['company_name' => 'Alpha']);

        $page = $this->actingAs(User::factory()->create())->get('/leads?q=%25')->viewData('leads');
        $this->assertSame(0, $page->total());
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())->get('/leads?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_follow_up_due_filter(): void
    {
        $user = User::factory()->create();
        Lead::factory()->create(['company_name' => 'Overdue Co', 'next_follow_up_at' => now()->subDay()]);
        Lead::factory()->create(['company_name' => 'Future Co', 'next_follow_up_at' => now()->addDay()]);

        $this->actingAs($user)->get('/leads?follow_up=due')->assertSee('Overdue Co')->assertDontSee('Future Co');
        $this->actingAs($user)->get('/dashboard')->assertViewHas('followUpsDue', 1);
    }

    public function test_follow_up_can_be_scheduled_and_cleared(): void
    {
        $lead = Lead::factory()->create();
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->post("/leads/{$lead->id}/follow-up", ['next_follow_up_at' => '2026-11-05 10:00'])->assertSessionHas('status');
        $this->assertSame('2026-11-05 10:00', $lead->fresh()->next_follow_up_at->format('Y-m-d H:i'));

        $this->actingAs($operator)->post("/leads/{$lead->id}/follow-up", ['next_follow_up_at' => ''])->assertSessionHas('status');
        $this->assertNull($lead->fresh()->next_follow_up_at);
        $this->assertSame(2, $lead->activities()->where('activity_type', 'follow_up_scheduled')->count());
    }

    public function test_notes_are_added_to_activity_history_and_escaped(): void
    {
        $lead = Lead::factory()->create();
        $user = User::factory()->operator()->create();

        $this->actingAs($user)->post("/leads/{$lead->id}/notes", ['note' => '<script>alert(1)</script>Called, no answer']);

        $this->actingAs($user)->get("/leads/{$lead->id}")
            ->assertOk()
            ->assertSee('Called, no answer')
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_viewer_can_read_but_not_change_leads(): void
    {
        $viewer = User::factory()->create();
        $lead = Lead::factory()->create();

        $this->actingAs($viewer)->get('/leads')->assertOk()->assertDontSee('New lead');
        $this->actingAs($viewer)->get("/leads/{$lead->id}")->assertOk()->assertDontSee('Change status');
        $this->actingAs($viewer)->get('/leads/create')->assertForbidden();
        $this->actingAs($viewer)->post('/leads', $this->payload())->assertForbidden();
        $this->actingAs($viewer)->put("/leads/{$lead->id}", $this->payload())->assertForbidden();
        $this->actingAs($viewer)->post("/leads/{$lead->id}/status", ['status' => 'researched'])->assertForbidden();
        $this->actingAs($viewer)->post("/leads/{$lead->id}/notes", ['note' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post("/leads/{$lead->id}/follow-up", ['next_follow_up_at' => null])->assertForbidden();
        $this->actingAs($viewer)->post("/leads/{$lead->id}/opt-out", ['opt_out_reason' => 'x', 'confirm' => 1])->assertForbidden();
        $this->actingAs($viewer)->post("/leads/{$lead->id}/convert", ['confirm' => 1])->assertForbidden();

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_guest_cannot_reach_crm_routes(): void
    {
        $lead = Lead::factory()->create();

        $this->get('/leads')->assertRedirect('/login');
        $this->get("/leads/{$lead->id}")->assertRedirect('/login');
        $this->post("/leads/{$lead->id}/convert")->assertRedirect('/login');
        $this->get('/customers')->assertRedirect('/login');
        $this->get('/service-packages')->assertRedirect('/login');
    }
}
