<?php

namespace Tests\Feature\Proposals;

use App\Enums\ProposalStatus;
use App\Enums\ToolExecutionStatus;
use App\Models\Proposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Governance\InteractsWithGovernance;
use Tests\TestCase;

class ProposalToolsTest extends TestCase
{
    use InteractsWithGovernance, InteractsWithProposals {
        InteractsWithProposals::setUpProposals as baseSetUpProposals;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGovernance();
        $this->baseSetUpProposals();
    }

    public function test_agents_draft_proposals_with_package_prices_and_recorded_contacts(): void
    {
        $this->grant('draft_proposal', 'validate_proposal');

        $execution = $this->useTool('draft_proposal', ['lead_id' => $this->lead->id, 'service_package_id' => $this->package->id, 'title' => 'Website for Acme', 'introduction' => 'Based on your current site.']);

        $this->assertSame(ToolExecutionStatus::Succeeded, $execution->status, (string) $execution->error_message);
        $proposal = Proposal::query()->sole();
        $this->assertSame([ProposalStatus::Draft, 150_000, 'ama@acme-bakery.example', $this->agent->id], [$proposal->status, $proposal->amount_minor, $proposal->recipient_email, $proposal->created_by_agent_id]);
        $this->assertSame($this->agent->name, $proposal->creatorName());
        $this->assertSame(['proposal_id' => $proposal->id, 'reference' => $proposal->label(), 'status' => 'draft', 'total' => 'GHS 1,500.00', 'problems' => [], 'sent' => false], $execution->result_summary);
        $this->assertSame('agent', $proposal->events()->sole()->actor_type);

        $check = $this->useTool('validate_proposal', ['proposal_id' => $proposal->id]);
        $this->assertTrue($check->result_summary['valid']);
    }

    public function test_agents_cannot_set_prices_discounts_or_recipients(): void
    {
        $this->grant('draft_proposal');
        $base = ['lead_id' => $this->lead->id, 'service_package_id' => $this->package->id, 'title' => 'x'];

        foreach ([['amount_minor' => 1], ['discount_value' => 50], ['recipient_email' => 'attacker@example.com'], ['extra_items' => []], ['status' => 'approved']] as $extra) {
            $this->assertSame('invalid_input', $this->useTool('draft_proposal', $base + $extra)->error_code, json_encode($extra));
        }
        $this->assertSame(0, Proposal::query()->count());
    }

    public function test_agents_cannot_draft_for_opted_out_leads_or_submit_or_send(): void
    {
        $this->grant('draft_proposal');
        $this->lead->forceFill(['do_not_contact' => true])->save();

        $execution = $this->useTool('draft_proposal', ['lead_id' => $this->lead->id, 'service_package_id' => $this->package->id, 'title' => 'x']);

        $this->assertSame(ToolExecutionStatus::Failed, $execution->status);
        $this->assertSame(0, Proposal::query()->count());
        $this->assertSame('prohibited', $this->useTool('send_external_message', ['to' => 'ama@acme-bakery.example'])->error_code);
        $this->assertSame('unknown_tool', $this->useTool('send_proposal', ['proposal_id' => 1])->error_code);

        // Proposal sends are approved per version, not through decision-only requests.
        $this->grant('request_approval');
        $this->assertSame('invalid_input', $this->useTool('request_approval', ['action' => 'send_proposal', 'summary' => 'Send it', 'reason' => 'Ready', 'risk_level' => 'medium', 'rollback_plan' => 'None'])->error_code);
    }

    public function test_validate_proposal_reports_problems(): void
    {
        $this->grant('validate_proposal');
        $proposal = $this->draft();
        $this->package->update(['is_active' => false]);

        $check = $this->useTool('validate_proposal', ['proposal_id' => $proposal->id]);

        $this->assertFalse($check->result_summary['valid']);
        $this->assertContains('The service package is inactive or missing.', $check->result_summary['problems']);
    }
}
