<?php

namespace Tests\Feature\Acceptance;

use App\Agents\ToolCatalog;
use App\Enums\ApprovalStatus;
use App\Enums\CheckStatus;
use App\Enums\ReleaseStatus;
use App\Models\Customer;
use App\Models\MaintenanceFinding;
use App\Models\Proposal;
use App\Models\User;
use App\Proposals\ProposalRefused;
use App\Releases\ReleaseRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Governance\InteractsWithGovernance;
use Tests\Feature\Maintenance\InteractsWithMaintenance;
use Tests\TestCase;

/**
 * The five cases the master plan requires before the first pilot, in one
 * place. Each is also covered in more depth in its module's tests.
 */
class MandatoryAcceptanceTest extends TestCase
{
    use InteractsWithGovernance, InteractsWithMaintenance {
        InteractsWithMaintenance::setUpMaintenance as baseSetUpMaintenance;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGovernance();
        $this->baseSetUpMaintenance();
    }

    private function refused(callable $action, string $exception): \Throwable
    {
        try {
            $action();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);

            return $e;
        }
        $this->fail('Expected the action to be refused.');
    }

    public function test_case_1_an_agent_cannot_send_a_proposal_without_valid_approval(): void
    {
        $this->grant('draft_proposal', 'request_approval', 'send_external_message');
        $this->useTool('draft_proposal', ['lead_id' => $this->lead->id, 'service_package_id' => $this->package->id, 'title' => 'Website']);
        $proposal = Proposal::query()->where('created_by_agent_id', $this->agent->id)->sole();

        // No sending tool exists, generic sending is prohibited, and decision-only approvals cannot cover a send.
        $this->assertArrayNotHasKey('send_proposal', ToolCatalog::TOOLS);
        $this->assertSame('unknown_tool', $this->useTool('send_proposal', ['proposal_id' => $proposal->id])->error_code);
        $this->assertSame('prohibited', $this->useTool('send_external_message', ['to' => 'ama@acme-bakery.example'])->error_code);
        $this->assertSame('invalid_input', $this->useTool('request_approval', ['action' => 'send_proposal', 'summary' => 'Send', 'reason' => 'Ready', 'risk_level' => 'medium', 'rollback_plan' => 'None'])->error_code);

        // Submitted but not approved: even the founder cannot send, nor an operator.
        $proposal = $this->proposals()->submit($proposal, $this->operator);
        $this->refused(fn () => $this->proposals()->send($proposal, 'manual', $this->founder, 'x'), ProposalRefused::class);
        $this->refused(fn () => $this->proposals()->send($proposal, 'manual', $this->operator, 'x'), ProposalRefused::class);
        $this->assertNull($proposal->fresh()->sent_at);
    }

    public function test_case_2_a_modified_proposal_cannot_reuse_its_earlier_approval(): void
    {
        $proposal = $this->approved();
        DB::table('proposals')->where('id', $proposal->id)->update(['recipient_email' => 'someone-else@example.com']);

        $this->refused(fn () => $this->proposals()->send($proposal->fresh(), 'manual', $this->founder, 'x'), ProposalRefused::class);

        $this->assertSame(ApprovalStatus::Invalidated, $proposal->approvalRequest->fresh()->status);
        $this->assertNull($proposal->fresh()->sent_at);
    }

    public function test_case_3_a_retry_cannot_duplicate_a_consequential_external_action(): void
    {
        config(['company_os.proposals.email_enabled' => true, 'company_os.proposals.mailer' => 'array']);
        $proposal = $this->approved();

        $this->proposals()->send($proposal, 'email', $this->founder);
        DB::table('proposals')->where('id', $proposal->id)->update(['status' => 'approved']); // a stale retry
        $this->refused(fn () => $this->proposals()->send($proposal->fresh(), 'email', $this->founder), ProposalRefused::class);

        $this->assertSame(1, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count());
        $this->assertSame(ApprovalStatus::Executed, $proposal->approvalRequest->fresh()->status);
    }

    public function test_case_4_unauthorized_people_cannot_reach_customer_records(): void
    {
        $customer = $this->site->customer;
        $customer->forceFill(['billing_details' => 'IBAN GH00 SECRET 1234'])->save();
        $task = $this->startedVisit();
        $finding = $this->maintenance()->addFinding($task, ['severity' => 'low', 'description' => 'Visible finding', 'risky' => false], $this->operator);

        // Another customer's visit cannot be used to reach this finding.
        $other = Customer::factory()->create(['status' => 'active']);
        $otherPlan = $this->maintenance()->createPlan(['customer_id' => $other->id, 'plan_name' => 'Other', 'billing_frequency' => 'monthly', 'service_interval' => 'monthly', 'amount_minor' => 100, 'currency' => 'GHS'], $this->founder);
        $otherTask = $this->maintenance()->start($this->maintenance()->scheduleTask($otherPlan, today(), $this->operator), $this->operator);
        $this->actingAs($this->operator)->post("/maintenance/tasks/{$otherTask->id}/findings/{$finding->id}/resolve", ['status' => 'wont_fix', 'note' => 'x'])->assertNotFound();
        $this->assertSame('open', MaintenanceFinding::query()->whereKey($finding->id)->toBase()->value('status'));

        $pages = ["/customers/{$customer->id}", "/projects/{$this->site->id}", "/maintenance/tasks/{$task->id}", "/maintenance/plans/{$task->maintenance_plan_id}", "/proposals/{$this->site->proposal_id}"];

        // Guests are sent to log in.
        auth()->logout();
        foreach ($pages as $page) {
            $this->get($page)->assertRedirect('/login');
        }

        // A deactivated account is logged out on its next request.
        $former = User::factory()->operator()->create(['is_active' => false]);
        $this->actingAs($former)->get($pages[0])->assertRedirect('/login');
        $this->assertGuest();

        // Billing details are founder-only and stored encrypted.
        $this->actingAs(User::factory()->create())->get($pages[0])->assertOk()->assertDontSee('SECRET 1234');
        $this->actingAs($this->operator)->get($pages[0])->assertOk()->assertDontSee('SECRET 1234');
        $this->actingAs($this->founder)->get($pages[0])->assertOk()->assertSee('SECRET 1234');
        $this->assertStringNotContainsString('SECRET', (string) DB::table('customers')->where('id', $customer->id)->value('billing_details'));
        $this->assertArrayNotHasKey('billing_details', $customer->fresh()->toArray());

        // Viewers cannot change customer records.
        $this->actingAs(User::factory()->create())->put("/customers/{$customer->id}", ['company_name' => 'Changed'])->assertForbidden();
    }

    public function test_case_5_a_failed_mandatory_test_blocks_production_deployment(): void
    {
        $project = $this->readyProject();
        $release = $this->passChecks($this->release($project, 'production'));
        $this->releases()->recordCheck($release->checks()->where('check_key', 'automated_tests')->sole(), CheckStatus::Failed, 'CI run #43 failed', $this->operator);

        $this->refused(fn () => $this->releases()->submit($release, $this->operator), ReleaseRefused::class);
        $this->refused(fn () => $this->releases()->recordDeployment($release->fresh(), 'Deployed', $this->founder), ReleaseRefused::class);

        $this->assertSame(ReleaseStatus::Draft, $release->fresh()->status);
        $this->assertNull($release->fresh()->approval_request_id, 'No approval can even be requested, so none can override the failed test.');
    }
}
