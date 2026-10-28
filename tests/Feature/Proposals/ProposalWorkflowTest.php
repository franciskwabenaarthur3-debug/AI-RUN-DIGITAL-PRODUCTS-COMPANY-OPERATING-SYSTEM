<?php

namespace Tests\Feature\Proposals;

use App\Agents\ToolCatalog;
use App\Enums\ApprovalStatus;
use App\Enums\LeadStatus;
use App\Enums\ProposalStatus;
use App\Governance\ApprovalRefused;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalEvent;
use App\Models\ServicePackage;
use App\Proposals\ProposalRefused;
use App\Services\ApprovalService;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class ProposalWorkflowTest extends TestCase
{
    use InteractsWithProposals;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProposals();
    }

    private function sentMessages(): int
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
    }

    private function refusal(callable $fn): ProposalRefused
    {
        try {
            $fn();
        } catch (ProposalRefused $e) {
            return $e;
        }
        $this->fail('Expected the action to be refused.');
    }

    public function test_a_draft_uses_the_package_price_currency_scope_and_lead_contact(): void
    {
        $proposal = $this->draft();

        $this->assertSame('P-'.str_pad((string) $proposal->id, 5, '0', STR_PAD_LEFT), $proposal->reference);
        $this->assertSame(1, $proposal->version);
        $this->assertSame(ProposalStatus::Draft, $proposal->status);
        $this->assertSame('GHS', $proposal->currency);
        $this->assertSame(['Ama Mensah', 'ama@acme-bakery.example'], [$proposal->recipient_name, $proposal->recipient_email]);
        $this->assertSame(['Design', 'Build', 'Launch'], $proposal->scope_snapshot['scope']);
        $this->assertSame(['Business Website', 'Extra page'], $proposal->items->pluck('description')->all());
        $this->assertSame([150_000, 20_000], $proposal->items->pluck('line_total_minor')->all());
        $this->assertSame(170_000, $proposal->amount_minor);
        $this->assertSame(['created'], $proposal->events->pluck('event_type')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'proposal.created', 'actor_id' => $this->operator->id]);
    }

    public function test_drafts_are_refused_for_opted_out_leads_inactive_packages_or_two_recipients(): void
    {
        $optedOut = Lead::factory()->optedOut()->create();
        $customer = Customer::factory()->create();

        foreach ([
            ['lead_id' => $optedOut->id],
            ['service_package_id' => ServicePackage::factory()->inactive()->create()->id],
            ['customer_id' => $customer->id],
            ['lead_id' => null],
        ] as $override) {
            try {
                $this->draft($override);
                $this->fail('Expected a validation error for '.json_encode($override));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Proposal::query()->count());
    }

    public function test_drafts_can_be_edited_and_prices_are_recalculated(): void
    {
        $proposal = $this->draft();

        $this->proposals()->update($proposal, [
            'title' => 'Website and shop',
            'recipient_name' => 'Ama',
            'recipient_email' => 'orders@acme-bakery.example',
            'scope' => ['Design', 'Build', 'Online ordering'],
            'exclusions' => [],
            'extra_items' => [['description' => 'Online ordering', 'quantity' => 1, 'unit_price_minor' => 50_000]],
            'discount_type' => 'percent',
            'discount_value' => 10,
            'discount_reason' => 'Bundle',
            'payment_terms' => '50% upfront.',
            'expires_at' => now()->addDays(10)->toDateString(),
        ], $this->operator);

        $proposal->refresh();
        $this->assertSame([200_000, 20_000, 180_000], [$proposal->subtotal_minor, $proposal->discount_minor, $proposal->amount_minor]);
        $this->assertSame(['Design', 'Build', 'Online ordering'], $proposal->scope_snapshot['scope']);
        $this->assertSame([], $proposal->scope_snapshot['exclusions']);
        $this->assertSame(2, $proposal->items()->count());
        $this->assertSame('edited', $proposal->events()->pluck('event_type')->last());
    }

    public function test_submitting_freezes_the_version_and_requests_approval_of_the_exact_content(): void
    {
        $proposal = $this->submitted();

        $this->assertSame(ProposalStatus::PendingApproval, $proposal->status);
        $approval = $proposal->approvalRequest;
        $this->assertSame(['proposal.send', 'proposal', $proposal->id], [$approval->action_type, $approval->resource_type, $approval->resource_id]);
        $this->assertSame('ama@acme-bakery.example', $approval->payload_snapshot['recipient']['email']);
        $this->assertSame(170_000, $approval->payload_snapshot['amount_minor']);
        $this->assertCount(2, $approval->payload_snapshot['items']);
        $this->assertSame($this->proposals()->contentHash($approval->payload_snapshot), $proposal->content_hash);

        $this->expectException(LogicException::class);
        $proposal->update(['amount_minor' => 1]);
    }

    public function test_items_of_a_submitted_version_cannot_change(): void
    {
        $proposal = $this->submitted();

        $this->expectException(LogicException::class);
        $proposal->items()->first()->update(['unit_price_minor' => 1]);
    }

    public function test_a_proposal_that_fails_checks_cannot_be_submitted(): void
    {
        $proposal = $this->draft(['recipient_email' => null]);
        Lead::query()->whereKey($this->lead->id)->update(['email' => null]);
        DB::table('proposals')->where('id', $proposal->id)->update(['recipient_email' => null]);

        $e = $this->refusal(fn () => $this->proposals()->submit($proposal, $this->operator));

        $this->assertContains('A valid recipient email is required.', $e->problems);
        $this->assertSame(ProposalStatus::Draft, $proposal->fresh()->status);
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_acceptance_case_1_a_proposal_cannot_be_sent_without_valid_approval(): void
    {
        $proposal = $this->submitted();

        // Pending approval: refused, nothing executes.
        $e = $this->refusal(fn () => $this->proposals()->send($proposal, 'manual', $this->founder, 'x'));
        $this->assertStringContainsString('only the latest approved version', $e->getMessage());

        // Even with the status forced to approved, the approval engine refuses a pending request.
        DB::table('proposals')->where('id', $proposal->id)->update(['status' => 'approved']);
        $e = $this->refusal(fn () => $this->proposals()->send($proposal->fresh(), 'manual', $this->founder, 'x'));
        $this->assertStringContainsString('not been approved', $e->getMessage());
        $this->assertNull($proposal->fresh()->sent_at);

        // Operators cannot send, and agents have no tool for it.
        $this->assertStringContainsString('Only the founder', $this->refusal(fn () => $this->proposals()->send($proposal->fresh(), 'manual', $this->operator, 'x'))->getMessage());
        $this->assertArrayNotHasKey('send_proposal', ToolCatalog::TOOLS);
    }

    public function test_founder_approval_then_manual_send_is_recorded_once(): void
    {
        $proposal = $this->approved();
        $this->assertSame(ProposalStatus::Approved, $proposal->status);
        $this->assertNotNull($proposal->approved_at);

        $sent = $this->proposals()->send($proposal, 'manual', $this->founder, 'Emailed from my inbox on 28 Oct');

        $this->assertSame(ProposalStatus::Sent, $sent->status);
        $this->assertSame(['manual', 'recorded_manually'], [$sent->delivery_method, $sent->delivery_status]);
        $this->assertSame(ApprovalStatus::Executed, $proposal->approvalRequest->fresh()->status);
        $this->assertSame(['created', 'submitted', 'approved', 'sent'], $sent->events()->pluck('event_type')->all());
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $this->lead->id, 'activity_type' => 'proposal_sent']);
    }

    public function test_acceptance_case_3_a_retry_cannot_send_twice(): void
    {
        config(['company_os.proposals.email_enabled' => true, 'company_os.proposals.mailer' => 'array']);
        $proposal = $this->approved();

        $this->proposals()->send($proposal, 'email', $this->founder);
        $stale = Proposal::query()->find($proposal->id);
        DB::table('proposals')->where('id', $proposal->id)->update(['status' => 'approved']); // a stale double-click
        $e = $this->refusal(fn () => $this->proposals()->send($stale->fresh(), 'email', $this->founder));

        $this->assertStringContainsString('already executed', $e->getMessage());
        $this->assertSame(1, $this->sentMessages());
    }

    public function test_email_sending_records_the_mail_service_response(): void
    {
        $proposal = $this->approved();
        $this->assertStringContainsString('not configured', $this->refusal(fn () => $this->proposals()->send($proposal, 'email', $this->founder))->getMessage());

        config(['company_os.proposals.email_enabled' => true, 'company_os.proposals.mailer' => 'array']);
        $sent = $this->proposals()->send($proposal->fresh(), 'email', $this->founder);

        $this->assertSame(1, $this->sentMessages());
        $message = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('ama@acme-bakery.example', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString($sent->reference, $message->getSubject());
        $this->assertStringContainsString('GHS 1,700.00', $message->getHtmlBody());
        // The array mailer keeps the message locally; that is recorded, not called a delivery.
        $this->assertSame('not_delivered_local_mailer', $sent->delivery_status);
        $this->assertNotEmpty($sent->delivery_reference);
        $this->assertSame($sent->delivery_reference, $proposal->approvalRequest->fresh()->execution_result['message_id']);
    }

    public function test_a_failed_send_is_recorded_and_needs_a_new_approval(): void
    {
        config(['company_os.proposals.email_enabled' => true, 'company_os.proposals.mailer' => 'missing-mailer']);
        $proposal = $this->approved();

        $e = $this->refusal(fn () => $this->proposals()->send($proposal, 'email', $this->founder));

        $this->assertStringContainsString('Sending failed', $e->getMessage());
        $proposal->refresh();
        $this->assertSame(ProposalStatus::Approved, $proposal->status);
        $this->assertSame('failed', $proposal->delivery_status);
        $this->assertSame(ApprovalStatus::Failed, $proposal->approvalRequest->status);

        $again = $this->proposals()->submit($proposal, $this->operator);
        $this->assertSame(ProposalStatus::PendingApproval, $again->status);
        $this->assertNotSame($proposal->approval_request_id, $again->approval_request_id);
        $this->assertContains('approval_requested_again', $again->events()->pluck('event_type')->all());
    }

    public function test_acceptance_case_2_a_modified_proposal_cannot_reuse_its_approval(): void
    {
        $proposal = $this->approved();

        // Content changed behind the application's back.
        DB::table('proposal_items')->where('proposal_id', $proposal->id)->where('position', 1)->update(['unit_price_minor' => 1, 'line_total_minor' => 2]);
        DB::table('proposals')->where('id', $proposal->id)->update(['subtotal_minor' => 150_002, 'amount_minor' => 150_002]);

        $e = $this->refusal(fn () => $this->proposals()->send($proposal->fresh(), 'manual', $this->founder, 'x'));

        $this->assertStringContainsString('differs from what was approved', $e->getMessage());
        $this->assertSame(ApprovalStatus::Invalidated, $proposal->approvalRequest->fresh()->status);
        $this->assertNull($proposal->fresh()->sent_at);
    }

    public function test_a_new_version_withdraws_the_approved_one_and_needs_its_own_approval(): void
    {
        $v1 = $this->approved();

        $v2 = $this->proposals()->newVersion($v1, $this->operator);

        $v1->refresh();
        $this->assertSame(ProposalStatus::Withdrawn, $v1->status);
        $this->assertSame(ApprovalStatus::Invalidated, $v1->approvalRequest->status);
        $this->assertSame([2, ProposalStatus::Draft, $v1->reference, $v1->id], [$v2->version, $v2->status, $v2->reference, $v2->previous_version_id]);
        $this->assertSame($v1->amount_minor, $v2->amount_minor);
        $this->assertNull($v2->approval_request_id);
        $this->assertFalse($v1->isLatest());

        $this->refusal(fn () => $this->proposals()->send($v2, 'manual', $this->founder, 'x'));
        $this->refusal(fn () => $this->proposals()->send($v1, 'manual', $this->founder, 'x'));
        $this->refusal(fn () => $this->proposals()->newVersion($v1, $this->operator));

        $v2 = $this->proposals()->submit($v2, $this->operator);
        $this->assertNotSame($v1->approval_request_id, $v2->approval_request_id);
    }

    public function test_a_new_version_reprices_the_package_line(): void
    {
        $v1 = $this->submitted();
        app(ApprovalService::class)->decide($v1->approvalRequest, $this->founder, false, 'Price went up');
        $this->assertSame(ProposalStatus::Rejected, $v1->fresh()->status);
        $this->assertSame('Price went up', $v1->events()->where('event_type', 'rejected')->sole()->metadata['reason']);

        $this->package->update(['base_price_minor' => 175_000]);
        $v2 = $this->proposals()->newVersion($v1->fresh(), $this->operator);

        $this->assertSame(ProposalStatus::Rejected, $v1->fresh()->status, 'A rejected version stays as it was.');
        $this->assertSame(195_000, $v2->amount_minor);
    }

    public function test_sending_rechecks_the_recipient(): void
    {
        $proposal = $this->approved();
        app(LeadService::class)->optOut($this->lead, 'Asked us to stop', $this->founder);

        $e = $this->refusal(fn () => $this->proposals()->send($proposal, 'manual', $this->founder, 'x'));

        $this->assertContains('The lead has opted out, so it cannot receive a proposal.', $e->problems);
        $this->assertSame(ApprovalStatus::Approved, $proposal->approvalRequest->fresh()->status, 'The approval is not consumed.');
    }

    public function test_an_expired_approval_can_be_requested_again(): void
    {
        $proposal = $this->submitted();
        $this->travel(config('company_os.approvals.ttl_hours') + 1)->hours();

        $this->assertThrows(fn () => app(ApprovalService::class)->decide($proposal->approvalRequest, $this->founder, true), ApprovalRefused::class);
        $again = $this->proposals()->submit($proposal->fresh(), $this->operator);

        $this->assertSame(ProposalStatus::PendingApproval, $again->status);
        $this->assertSame(2, ApprovalRequest::query()->count());
    }

    public function test_acceptance_converts_the_lead_once_and_is_idempotent(): void
    {
        $proposal = $this->sentProposal();
        $this->lead->forceFill(['status' => LeadStatus::Qualified])->save();

        $this->proposals()->recordResponse($proposal, 'accepted', 'Email reply: "Go ahead"', Carbon::today(), $this->operator);
        $this->proposals()->recordResponse($proposal, 'accepted', 'Same email again', Carbon::today(), $this->operator);

        $proposal->refresh();
        $this->assertSame(ProposalStatus::Accepted, $proposal->status);
        $this->assertSame('Email reply: "Go ahead"', $proposal->response_note);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(LeadStatus::Converted, $this->lead->fresh()->status);
        $this->assertSame(1, ProposalEvent::query()->where('proposal_id', $proposal->id)->where('event_type', 'accepted')->count());

        $this->refusal(fn () => $this->proposals()->recordResponse($proposal, 'declined', 'Changed mind', Carbon::today(), $this->operator));
    }

    public function test_responses_are_only_recorded_for_sent_proposals(): void
    {
        $proposal = $this->approved();

        $this->refusal(fn () => $this->proposals()->recordResponse($proposal, 'accepted', 'Model said they agreed', Carbon::today(), $this->operator));
        $this->assertSame(ProposalStatus::Approved, $proposal->fresh()->status);

        $declined = $this->proposals()->recordResponse($this->proposals()->send($proposal, 'manual', $this->founder, 'x'), 'declined', 'Too expensive', Carbon::today(), $this->operator);
        $this->assertSame(ProposalStatus::Declined, $declined->status);
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_withdrawing_invalidates_an_open_approval(): void
    {
        $proposal = $this->submitted();

        $this->proposals()->withdraw($proposal, 'Client paused the project', $this->operator);

        $this->assertSame(ProposalStatus::Withdrawn, $proposal->fresh()->status);
        $this->assertSame(ApprovalStatus::Invalidated, $proposal->approvalRequest->fresh()->status);
        $this->assertThrows(fn () => app(ApprovalService::class)->decide($proposal->approvalRequest->fresh(), $this->founder, true), ApprovalRefused::class);
    }

    public function test_sent_proposals_expire_after_their_validity_date(): void
    {
        $proposal = $this->sentProposal(['expires_at' => now()->addDays(3)->toDateString()]);
        $fresh = $this->sentProposal();

        $this->travel(4)->days();
        $this->artisan('company-os:expire-proposals')->assertSuccessful();

        $this->assertSame(ProposalStatus::Expired, $proposal->fresh()->status);
        $this->assertSame(ProposalStatus::Sent, $fresh->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'proposal.expired', 'actor_type' => 'system']);
    }

    public function test_proposal_events_are_append_only(): void
    {
        $event = $this->draft()->events()->first();

        $this->expectException(LogicException::class);
        $event->update(['event_type' => 'sent']);
    }
}
