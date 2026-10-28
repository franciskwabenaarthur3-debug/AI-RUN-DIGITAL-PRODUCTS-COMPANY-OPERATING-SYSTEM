<?php

namespace Tests\Feature\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Customer;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalPagesTest extends TestCase
{
    use InteractsWithProposals;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProposals();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'lead_id' => $this->lead->id,
            'service_package_id' => $this->package->id,
            'title' => 'New website',
            'extra_items' => [
                ['description' => 'Logo refresh', 'quantity' => '1', 'unit_price' => '300.00'],
                ['description' => '', 'quantity' => '', 'unit_price' => ''],
            ],
            'discount_type' => 'percent',
            'discount_value' => '10',
            'discount_reason' => 'First project',
        ], $overrides);
    }

    public function test_operator_creates_a_draft_from_the_form_and_prices_come_from_the_server(): void
    {
        $response = $this->actingAs($this->operator)->post('/proposals', $this->form([
            'amount_minor' => 1, 'subtotal_minor' => 1, 'status' => 'approved',
        ]));

        $proposal = Proposal::query()->sole();
        $response->assertRedirect("/proposals/{$proposal->id}");
        $this->assertSame(ProposalStatus::Draft, $proposal->status);
        // 1500 + 300 = 1800, less 10% = 1620.
        $this->assertSame([180_000, 18_000, 162_000], [$proposal->subtotal_minor, $proposal->discount_minor, $proposal->amount_minor]);
        $this->assertSame(2, $proposal->items()->count());
    }

    public function test_form_validation(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAs($this->operator)->post('/proposals', $this->form(['customer_id' => $customer->id]))->assertSessionHasErrors('lead_id');
        $this->actingAs($this->operator)->post('/proposals', $this->form(['lead_id' => null]))->assertSessionHasErrors('lead_id');
        $this->actingAs($this->operator)->post('/proposals', $this->form(['discount_value' => '50']))->assertSessionHasErrors('discount_value');
        $this->actingAs($this->operator)->post('/proposals', $this->form(['discount_reason' => '']))->assertSessionHasErrors('discount_reason');
        $this->actingAs($this->operator)->post('/proposals', $this->form(['extra_items' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '-5']]]))->assertSessionHasErrors('extra_items.0.unit_price');
        $this->assertSame(0, Proposal::query()->count());
    }

    public function test_viewers_can_read_but_not_change_proposals(): void
    {
        $viewer = User::factory()->create();
        $proposal = $this->draft();

        $this->actingAs($viewer)->get('/proposals')->assertOk()->assertDontSee('New proposal');
        $this->actingAs($viewer)->get("/proposals/{$proposal->id}")->assertOk()->assertDontSee('Submit for approval');
        $this->actingAs($viewer)->get('/proposals/create')->assertForbidden();
        $this->actingAs($viewer)->post('/proposals', $this->form())->assertForbidden();
        $this->actingAs($viewer)->post("/proposals/{$proposal->id}/submit")->assertForbidden();
        $this->actingAs($viewer)->post("/proposals/{$proposal->id}/withdraw", ['reason' => 'x', 'confirm' => '1'])->assertForbidden();
        $this->assertSame(ProposalStatus::Draft, $proposal->fresh()->status);
    }

    public function test_submitted_versions_cannot_be_edited_through_the_ui(): void
    {
        $proposal = $this->submitted();

        $this->actingAs($this->operator)->get("/proposals/{$proposal->id}/edit")->assertRedirect("/proposals/{$proposal->id}")->assertSessionHasErrors('proposal');
        $this->actingAs($this->operator)->put("/proposals/{$proposal->id}", $this->form([
            'lead_id' => null, 'service_package_id' => null, 'scope' => 'Design', 'payment_terms' => 'Now', 'expires_at' => now()->addWeek()->toDateString(),
        ]))->assertSessionHasErrors();
        $this->assertSame(170_000, $proposal->fresh()->amount_minor);
    }

    public function test_only_the_founder_sends_with_confirmation(): void
    {
        $proposal = $this->approved();

        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/send", ['method' => 'manual', 'note' => 'x', 'confirm' => '1'])->assertForbidden();
        $this->actingAs($this->founder)->post("/proposals/{$proposal->id}/send", ['method' => 'manual', 'note' => 'x'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->founder)->post("/proposals/{$proposal->id}/send", ['method' => 'manual', 'confirm' => '1'])->assertSessionHasErrors('note');
        $this->actingAs($this->founder)->post("/proposals/{$proposal->id}/send", ['method' => 'email', 'confirm' => '1'])->assertSessionHasErrors('proposal');
        $this->assertSame(ProposalStatus::Approved, $proposal->fresh()->status);

        $this->actingAs($this->founder)->post("/proposals/{$proposal->id}/send", ['method' => 'manual', 'note' => 'Emailed it', 'confirm' => '1'])->assertSessionHas('status');
        $this->assertSame(ProposalStatus::Sent, $proposal->fresh()->status);
    }

    public function test_recording_a_response_needs_evidence_and_a_past_date(): void
    {
        $proposal = $this->sentProposal();

        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/respond", ['outcome' => 'accepted', 'note' => '', 'responded_at' => today()->toDateString(), 'confirm' => '1'])->assertSessionHasErrors('note');
        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/respond", ['outcome' => 'accepted', 'note' => 'Reply', 'responded_at' => today()->addDay()->toDateString(), 'confirm' => '1'])->assertSessionHasErrors('responded_at');
        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/respond", ['outcome' => 'accepted', 'note' => 'Reply', 'responded_at' => today()->toDateString()])->assertSessionHasErrors('confirm');

        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/respond", ['outcome' => 'changes_requested', 'note' => 'Wants a shop', 'responded_at' => today()->toDateString(), 'confirm' => '1'])->assertSessionHas('status');
        $this->assertSame(ProposalStatus::ChangesRequested, $proposal->fresh()->status);

        $this->actingAs($this->operator)->post("/proposals/{$proposal->id}/new-version")->assertRedirect();
        $this->assertSame(2, Proposal::query()->where('reference', $proposal->reference)->max('version'));
    }

    public function test_pages_show_price_breakdown_versions_history_and_preview(): void
    {
        $v1 = $this->approved();
        $v2 = $this->proposals()->newVersion($v1, $this->operator);

        $this->actingAs($this->operator)->get("/proposals/{$v2->id}")->assertOk()
            ->assertSee('Price breakdown')->assertSee('GHS 1,700.00')->assertSee('Version 1')->assertSee('Version created')->assertSee('Submit for approval');
        $this->actingAs($this->operator)->get("/proposals/{$v1->id}")->assertOk()->assertSee('A newer version exists')->assertSee('Superseded');
        $this->actingAs($this->operator)->get("/proposals/{$v1->id}/preview")->assertOk()
            ->assertSee('New website for Acme Bakery')->assertSee('Not included')->assertSee('valid until', false);
        $this->actingAs($this->operator)->get("/proposals/{$v2->id}/edit")->assertOk()->assertSee('Extra page');

        $index = $this->actingAs($this->operator)->get('/proposals')->assertOk();
        $index->assertSee($v2->label())->assertDontSee($v1->label());

        $this->actingAs($this->founder)->get("/approvals/{$v1->approval_request_id}")->assertOk()->assertSee("Proposal #{$v1->id}")->assertSee('Preview what the recipient will get');
        $this->actingAs($this->operator)->get("/leads/{$this->lead->id}")->assertOk()->assertSee($v2->label())->assertSee('New proposal');
    }

    public function test_dashboard_counts_proposals(): void
    {
        $this->submitted();
        $this->sentProposal();

        $this->actingAs($this->founder)->get('/dashboard')->assertOk()
            ->assertSee('Proposals awaiting approval')->assertSee('1 sent, awaiting an answer');
    }
}
