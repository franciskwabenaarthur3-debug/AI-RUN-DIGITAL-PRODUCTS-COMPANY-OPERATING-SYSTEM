<?php

namespace Tests\Feature\Governance;

use App\Agents\Tools\ToolGateway;
use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\RiskLevel;
use App\Enums\ToolExecutionStatus;
use App\Governance\ApprovalRefused;
use App\Governance\Payload;
use App\Jobs\ExecuteApprovedAction;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\CompanySetting;
use App\Models\ToolExecution;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use InteractsWithGovernance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGovernance();
        $this->grant('prepare_release');
    }

    private function approvals(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    /** A Level 2 tool call, which leaves a pending approval request. */
    private function pending(array $input = ['version' => '1.0.0']): ToolExecution
    {
        return $this->useTool('prepare_release', $input);
    }

    private function assertRefused(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected ApprovalRefused({$code}).");
        } catch (ApprovalRefused $e) {
            $this->assertSame($code, $e->reasonCode);
        }
    }

    public function test_level_two_call_creates_a_pending_request_with_snapshot_and_hash_and_does_not_run(): void
    {
        $execution = $this->pending(['version' => '1.0.0', 'notes' => 'First']);
        $approval = $execution->approvalRequest;

        $this->assertSame(ToolExecutionStatus::AwaitingApproval, $execution->status);
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertEquals(['notes' => 'First', 'version' => '1.0.0'], $approval->payload_snapshot);
        $this->assertSame(Payload::hash('prepare_release', null, null, ['version' => '1.0.0', 'notes' => 'First']), $approval->payload_hash);
        $this->assertSame(RiskLevel::High, $approval->risk_level);
        $this->assertSame('Delete the release draft.', $approval->rollback_plan);
        $this->assertTrue($approval->expires_at->isFuture());
        $this->assertSame($this->agent->id, $approval->requested_by_agent_id);
        $this->assertSame(0, $this->release->runs);
        $this->assertDatabaseHas('audit_logs', ['action' => 'approval.requested', 'actor_type' => 'agent', 'actor_id' => $this->agent->id]);
    }

    public function test_hash_ignores_key_order(): void
    {
        $this->assertSame(
            Payload::hash('x', 'lead', 1, ['a' => 1, 'b' => ['d' => 2, 'c' => 3]]),
            Payload::hash('x', 'lead', 1, ['b' => ['c' => 3, 'd' => 2], 'a' => 1]),
        );
        $this->assertNotSame(Payload::hash('x', 'lead', 1, ['a' => 1]), Payload::hash('x', 'lead', 2, ['a' => 1]));
    }

    public function test_founder_approval_executes_exactly_once(): void
    {
        $execution = $this->pending();
        $approval = $this->approvals()->decide($execution->approvalRequest, $this->founder, true, 'Looks right.');

        $this->assertSame(1, $this->release->runs);
        $this->assertSame(ApprovalStatus::Executed, $approval->fresh()->status);
        $this->assertNotNull($approval->fresh()->executed_at);
        $this->assertSame(['version' => '1.0.0'], $approval->fresh()->execution_result);
        $execution->refresh();
        $this->assertSame(ToolExecutionStatus::Succeeded, $execution->status);
        $this->assertSame('release-1.0.0', $execution->external_reference);

        $decision = ApprovalDecision::query()->sole();
        $this->assertSame('approved', $decision->decision);
        $this->assertSame($this->founder->id, $decision->approver_user_id);
        $this->assertSame($approval->payload_hash, $decision->payload_hash);
    }

    public function test_cannot_execute_without_a_valid_approval(): void
    {
        $approval = $this->pending()->approvalRequest;

        $this->assertRefused('pending', fn () => $this->approvals()->execute($approval, 'prepare_release', null, null, ['version' => '1.0.0'], fn () => []));
        $this->assertNull(app(ToolGateway::class)->executeApproved($approval->id + 1000), 'No approval: nothing to run.');
        $this->assertRefused('missing', fn () => $this->approvals()->execute(new ApprovalRequest(['id' => 999]), 'prepare_release', null, null, [], fn () => []));

        app(ToolGateway::class)->executeApproved($approval->id);
        $this->assertSame(0, $this->release->runs);
        $this->assertSame(ToolExecutionStatus::AwaitingApproval, ToolExecution::query()->sole()->status, 'A stray job before approval changes nothing.');
    }

    public function test_wrong_approver_is_rejected(): void
    {
        $approval = $this->pending()->approvalRequest;

        foreach ([User::factory()->operator()->create(), User::factory()->create(), User::factory()->founder()->inactive()->create()] as $user) {
            $this->assertRefused('wrong_approver', fn () => $this->approvals()->decide($approval, $user, true));
        }

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
        $this->assertSame(0, ApprovalDecision::query()->count());
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'approval.decision_refused')->count());
    }

    public function test_rejected_request_blocks_execution(): void
    {
        $execution = $this->pending();
        $approval = $this->approvals()->decide($execution->approvalRequest, $this->founder, false, 'Not this week.');

        $this->assertSame(ApprovalStatus::Rejected, $approval->status);
        $this->assertSame(ToolExecutionStatus::Denied, $execution->fresh()->status);
        $this->assertSame('approval_rejected', $execution->fresh()->error_code);
        $this->assertRefused('rejected', fn () => $this->approvals()->execute($approval, 'prepare_release', null, null, ['version' => '1.0.0'], fn () => []));
        $this->assertSame(0, $this->release->runs);
        $this->assertDatabaseHas('audit_logs', ['action' => 'approval.rejected', 'actor_id' => $this->founder->id]);
    }

    public function test_rejection_needs_a_reason(): void
    {
        $this->expectException(LogicException::class);
        $this->approvals()->decide($this->pending()->approvalRequest, $this->founder, false, ' ');
    }

    public function test_expired_request_cannot_be_approved(): void
    {
        $execution = $this->pending();
        $this->travel(config('company_os.approvals.ttl_hours') + 1)->hours();

        $this->assertRefused('expired', fn () => $this->approvals()->decide($execution->approvalRequest, $this->founder, true));
        $this->assertSame(ApprovalStatus::Expired, $execution->approvalRequest->fresh()->status);
        $this->assertSame(ToolExecutionStatus::Denied, $execution->fresh()->status);
        $this->assertSame(0, ApprovalDecision::query()->count());
    }

    public function test_approval_that_expires_before_execution_blocks_it(): void
    {
        Queue::fake();
        $execution = $this->pending();
        $this->approvals()->decide($execution->approvalRequest, $this->founder, true);
        Queue::assertPushed(ExecuteApprovedAction::class);

        $this->travel(config('company_os.approvals.ttl_hours') + 1)->hours();
        app(ToolGateway::class)->executeApproved($execution->approval_request_id);

        $this->assertSame(0, $this->release->runs);
        $this->assertSame(ApprovalStatus::Expired, $execution->approvalRequest->fresh()->status);
        $this->assertSame(ToolExecutionStatus::Denied, $execution->fresh()->status);
    }

    public function test_modified_payload_cannot_reuse_an_approval(): void
    {
        Queue::fake();
        $approval = $this->approvals()->decide($this->pending()->approvalRequest, $this->founder, true);

        $this->assertRefused('payload_mismatch', fn () => $this->approvals()->execute($approval, 'prepare_release', null, null, ['version' => '9.9.9'], fn () => $this->fail('The effect ran.')));

        $this->assertSame(ApprovalStatus::Invalidated, $approval->fresh()->status);
        $this->assertSame(0, $this->release->runs);
        $this->assertRefused('invalidated', fn () => $this->approvals()->execute($approval, 'prepare_release', null, null, ['version' => '1.0.0'], fn () => []));
        $this->assertDatabaseHas('audit_logs', ['action' => 'approval.invalidated', 'resource_id' => $approval->id]);
    }

    public function test_changing_the_input_creates_a_new_request_instead_of_reusing_one(): void
    {
        $first = $this->pending(['version' => '1.0.0']);
        $second = $this->pending(['version' => '1.0.1']);

        $this->assertNotSame($first->approval_request_id, $second->approval_request_id);
        $this->assertSame(2, ApprovalRequest::query()->count());
    }

    public function test_snapshot_tampered_in_the_database_is_detected(): void
    {
        Queue::fake();
        $execution = $this->pending();
        $approval = $this->approvals()->decide($execution->approvalRequest, $this->founder, true);

        DB::table('approval_requests')->where('id', $approval->id)->update(['payload_snapshot' => json_encode(['version' => '6.6.6'])]);
        app(ToolGateway::class)->executeApproved($approval->id);

        $this->assertSame(0, $this->release->runs);
        $this->assertSame(ApprovalStatus::Invalidated, $approval->fresh()->status);
        $this->assertSame('payload_mismatch', $execution->fresh()->error_code);
    }

    public function test_tampered_snapshot_cannot_be_approved(): void
    {
        $approval = $this->pending()->approvalRequest;
        DB::table('approval_requests')->where('id', $approval->id)->update(['payload_snapshot' => json_encode(['version' => '6.6.6'])]);

        $this->assertRefused('payload_mismatch', fn () => $this->approvals()->decide($approval, $this->founder, true));
        $this->assertSame(ApprovalStatus::Invalidated, $approval->fresh()->status);
    }

    public function test_snapshot_and_decisions_are_immutable(): void
    {
        $approval = $this->pending()->approvalRequest;

        try {
            $approval->update(['payload_snapshot' => ['version' => '2.0.0']]);
            $this->fail('Snapshot changed.');
        } catch (LogicException) {
        }

        Queue::fake();
        $this->approvals()->decide($approval, $this->founder, true);
        $this->assertRefused('not_decidable', fn () => $this->approvals()->decide($approval, $this->founder, false, 'Changed my mind'));

        $decision = ApprovalDecision::query()->sole();
        $this->expectException(LogicException::class);
        $decision->update(['decision' => 'rejected']);
    }

    public function test_database_refuses_a_second_decision_row(): void
    {
        Queue::fake();
        $approval = $this->approvals()->decide($this->pending()->approvalRequest, $this->founder, true);

        $this->expectException(UniqueConstraintViolationException::class);
        ApprovalDecision::query()->create(['approval_request_id' => $approval->id, 'approver_user_id' => $this->founder->id, 'decision' => 'rejected', 'payload_hash' => $approval->payload_hash]);
    }

    public function test_replays_and_duplicate_jobs_do_not_execute_twice(): void
    {
        $execution = $this->pending();
        $this->approvals()->decide($execution->approvalRequest, $this->founder, true);

        app(ToolGateway::class)->executeApproved($execution->approval_request_id);
        (new ExecuteApprovedAction($execution->approval_request_id))->handle(app(ToolGateway::class));
        $this->assertRefused('already_executed', fn () => $this->approvals()->execute($execution->approvalRequest, 'prepare_release', null, null, ['version' => '1.0.0'], fn () => []));

        $this->assertSame(1, $this->release->runs);
        $this->assertSame(ToolExecutionStatus::Succeeded, $execution->fresh()->status);
    }

    public function test_concurrent_claim_is_refused_while_executing(): void
    {
        Queue::fake();
        $approval = $this->approvals()->decide($this->pending()->approvalRequest, $this->founder, true);
        $second = null;

        $this->approvals()->execute($approval, 'prepare_release', null, null, ['version' => '1.0.0'], function () use ($approval, &$second) {
            $this->assertSame(ApprovalStatus::Executing, $approval->fresh()->status);
            try {
                $this->approvals()->execute($approval, 'prepare_release', null, null, ['version' => '1.0.0'], fn () => $this->fail('Second claim ran.'));
            } catch (ApprovalRefused $e) {
                $second = $e->reasonCode;
            }

            return ['ok' => true];
        });

        $this->assertSame('in_progress', $second);
        $this->assertSame(ApprovalStatus::Executed, $approval->fresh()->status);
    }

    public function test_failed_side_effect_is_recorded_and_not_retried(): void
    {
        $this->release->explode = true;
        $execution = $this->pending();
        $this->approvals()->decide($execution->approvalRequest, $this->founder, true);

        $this->assertSame(ApprovalStatus::Failed, $execution->approvalRequest->fresh()->status);
        $this->assertSame(ToolExecutionStatus::Failed, $execution->fresh()->status);
        $this->assertSame('The tool raised an unexpected error.', $execution->fresh()->error_message);

        $this->release->explode = false;
        app(ToolGateway::class)->executeApproved($execution->approval_request_id);
        $this->assertSame(0, $this->release->runs);
    }

    public function test_approval_never_overrides_a_disabled_agent_or_revoked_permission(): void
    {
        Queue::fake();
        $execution = $this->pending();
        $this->approvals()->decide($execution->approvalRequest, $this->founder, true);
        $this->agent->forceFill(['status' => AgentStatus::Disabled])->save();

        app(ToolGateway::class)->executeApproved($execution->approval_request_id);

        $this->assertSame(0, $this->release->runs);
        $this->assertSame(ApprovalStatus::Failed, $execution->approvalRequest->fresh()->status);
        $this->assertSame('agent_disabled', $execution->fresh()->error_code);

        $this->agent->forceFill(['status' => AgentStatus::Enabled])->save();
        $other = $this->pending(['version' => '2.0.0']);
        $this->approvals()->decide($other->approvalRequest, $this->founder, true);
        $this->agent->permissions()->update(['allowed' => false]);

        app(ToolGateway::class)->executeApproved($other->approval_request_id);
        $this->assertSame(0, $this->release->runs);
        $this->assertSame('not_permitted', $other->fresh()->error_code);
    }

    public function test_emergency_pause_holds_approved_actions_until_resume(): void
    {
        $execution = $this->pending();
        CompanySetting::current()->forceFill(['operating_status' => 'paused'])->save();

        $this->approvals()->decide($execution->approvalRequest, $this->founder, true);
        $this->assertSame(0, $this->release->runs);
        $this->assertSame(ApprovalStatus::Approved, $execution->approvalRequest->fresh()->status);

        CompanySetting::current()->forceFill(['operating_status' => 'active'])->save();
        $this->assertSame(1, $this->approvals()->dispatchApproved());
        $this->assertSame(1, $this->release->runs);
    }

    public function test_every_step_is_audited(): void
    {
        $execution = $this->pending();
        $this->approvals()->decide($execution->approvalRequest, $this->founder, true);

        $actions = DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
        foreach (['tool.approval_requested', 'approval.requested', 'approval.approved', 'approval.execution_started', 'approval.executed', 'tool.succeeded'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_sweep_expires_open_requests_and_flags_unknown_outcomes(): void
    {
        $stale = $this->pending(['version' => '1.0.0']);
        Queue::fake();
        $stuck = $this->approvals()->decide($this->pending(['version' => '2.0.0'])->approvalRequest, $this->founder, true);
        $stuck->forceFill(['status' => ApprovalStatus::Executing, 'execution_started_at' => now()])->save();

        $this->travel(config('company_os.approvals.ttl_hours') + 1)->hours();
        $this->artisan('company-os:expire-approvals')->expectsOutputToContain('Expired 1 approval request(s); flagged 1')->assertSuccessful();

        $this->assertSame(ApprovalStatus::Expired, $stale->approvalRequest->fresh()->status);
        $this->assertSame(ToolExecutionStatus::Denied, $stale->fresh()->status);
        $this->assertSame(ApprovalStatus::NeedsReconciliation, $stuck->fresh()->status);
        $this->assertRefused('needs_reconciliation', fn () => $this->approvals()->execute($stuck, 'prepare_release', null, null, ['version' => '2.0.0'], fn () => []));
    }

    public function test_request_is_idempotent_per_key(): void
    {
        $make = fn (array $payload) => $this->approvals()->request('agent.other', $payload, 'Why', RiskLevel::Low, 'None', 'key-1', $this->agent);

        $this->assertSame($make(['a' => 1])->id, $make(['a' => 1])->id);
        $this->expectException(LogicException::class);
        $make(['a' => 2]);
    }
}
