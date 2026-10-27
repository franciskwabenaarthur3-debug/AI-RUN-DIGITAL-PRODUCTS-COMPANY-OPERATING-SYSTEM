<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\RiskLevel;
use App\Enums\ToolExecutionStatus;
use App\Governance\ApprovalRefused;
use App\Governance\Payload;
use App\Jobs\ExecuteApprovedAction;
use App\Models\Agent;
use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\Task;
use App\Models\ToolExecution;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The approval engine. Requests snapshot the exact payload and its hash;
 * only an active founder can decide; execution needs an atomic claim on a
 * still-valid approval whose hash matches what is about to run.
 *
 * Side effects never run inside a database transaction: execute() claims
 * the request (approved -> executing) and commits, runs the effect, then
 * records executed or failed. A crash in between leaves the request in
 * executing, which the expire command flags for reconciliation.
 */
class ApprovalService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Create a pending request, or return the existing one for the same
     * idempotency key.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $evidence
     */
    public function request(
        string $actionType,
        array $payload,
        string $reason,
        RiskLevel $risk,
        string $rollbackPlan,
        string $idempotencyKey,
        Agent|User $requester,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?Task $task = null,
        array $evidence = [],
    ): ApprovalRequest {
        $hash = Payload::hash($actionType, $resourceType, $resourceId, $payload);

        if ($existing = ApprovalRequest::query()->where('idempotency_key', $idempotencyKey)->first()) {
            return $this->sameAction($existing, $hash);
        }

        try {
            return DB::transaction(function () use ($actionType, $payload, $reason, $risk, $rollbackPlan, $idempotencyKey, $requester, $resourceType, $resourceId, $task, $evidence, $hash) {
                $request = ApprovalRequest::query()->create([
                    'requested_by_agent_id' => $requester instanceof Agent ? $requester->id : null,
                    'requested_by_user_id' => $requester instanceof User ? $requester->id : null,
                    'task_id' => $task?->id,
                    'action_type' => $actionType,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'payload_snapshot' => $payload,
                    'payload_hash' => $hash,
                    'reason' => $reason,
                    'risk_level' => $risk,
                    'rollback_plan' => $rollbackPlan,
                    'evidence' => $evidence === [] ? null : array_values($evidence),
                    'status' => ApprovalStatus::Pending,
                    'expires_at' => now()->addHours(config('company_os.approvals.ttl_hours')),
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->audit->record('approval.requested', $request, [
                    'action_type' => $actionType,
                    'risk_level' => $risk->value,
                    'payload_hash' => $hash,
                ], $requester);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent call with the same key won the insert.
            return $this->sameAction(ApprovalRequest::query()->where('idempotency_key', $idempotencyKey)->sole(), $hash);
        }
    }

    /**
     * Record the founder's decision. Throws ApprovalRefused when the
     * approver is not allowed or the request can no longer be decided.
     */
    public function decide(ApprovalRequest $request, User $approver, bool $approve, ?string $reason = null): ApprovalRequest
    {
        if (! $approver->is_active || ! $approver->isFounder()) {
            $this->audit->record('approval.decision_refused', $request, ['reason' => 'wrong_approver'], $approver);

            throw new ApprovalRefused('wrong_approver', 'Only an active founder can decide approval requests.');
        }

        if (! $approve && blank($reason)) {
            throw new LogicException('A rejection needs a reason.');
        }

        [$request, $refusal] = DB::transaction(function () use ($request, $approver, $approve, $reason) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== ApprovalStatus::Pending) {
                return [$request, new ApprovalRefused('not_decidable', "This request is {$request->status->label()} and can no longer be decided.")];
            }

            if ($request->isExpired()) {
                $this->close($request, ApprovalStatus::Expired, 'approval_expired', 'The approval request expired before a decision.');

                return [$request, new ApprovalRefused('expired', 'This request expired before a decision was made.')];
            }

            if (! $request->snapshotIntact()) {
                $this->close($request, ApprovalStatus::Invalidated, 'payload_mismatch', 'The stored payload no longer matches its hash.');

                return [$request, new ApprovalRefused('payload_mismatch', 'The stored payload no longer matches its hash; the request was invalidated.')];
            }

            ApprovalDecision::query()->create([
                'approval_request_id' => $request->id,
                'approver_user_id' => $approver->id,
                'decision' => $approve ? 'approved' : 'rejected',
                'reason' => $reason,
                'payload_hash' => $request->payload_hash,
            ]);

            $request->status = $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected;
            $request->decided_at = now();
            $request->save();

            $this->audit->record($approve ? 'approval.approved' : 'approval.rejected', $request, [
                'payload_hash' => $request->payload_hash,
                'reason' => $reason,
            ], $approver);

            if ($approve) {
                if (ToolExecution::query()->where('approval_request_id', $request->id)->exists()) {
                    ExecuteApprovedAction::dispatch($request->id)->afterCommit();
                }
            } else {
                $this->closeLinkedExecutions($request, 'approval_rejected', 'The founder rejected the approval request.');
            }

            return [$request, null];
        });

        if ($refusal) {
            throw $refusal;
        }

        return $request;
    }

    /**
     * Run an approved action once. $payload is what is about to execute; it
     * must hash to the approved snapshot or the approval is invalidated.
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(ApprovalRequest): array<string, mixed>  $effect
     * @return array<string, mixed> the effect's result
     */
    public function execute(ApprovalRequest $request, string $actionType, ?string $resourceType, ?int $resourceId, array $payload, Closure $effect): array
    {
        $hash = Payload::hash($actionType, $resourceType, $resourceId, $payload);

        [$request, $refusal] = DB::transaction(function () use ($request, $hash) {
            $request = ApprovalRequest::query()->lockForUpdate()->find($request->id);

            if (! $request) {
                return [null, new ApprovalRefused('missing', 'No approval request exists for this action.')];
            }

            $refusal = match ($request->status) {
                ApprovalStatus::Approved => null,
                ApprovalStatus::Pending => new ApprovalRefused('pending', 'The action has not been approved yet.'),
                ApprovalStatus::Rejected => new ApprovalRefused('rejected', 'The action was rejected.'),
                ApprovalStatus::Expired => new ApprovalRefused('expired', 'The approval expired.'),
                ApprovalStatus::Invalidated => new ApprovalRefused('invalidated', 'The approval was invalidated.'),
                ApprovalStatus::Executing => new ApprovalRefused('in_progress', 'The action is already being executed.'),
                ApprovalStatus::Executed => new ApprovalRefused('already_executed', 'The action was already executed.'),
                ApprovalStatus::Failed => new ApprovalRefused('failed', 'The action failed; a new approval is needed to try again.'),
                ApprovalStatus::NeedsReconciliation => new ApprovalRefused('needs_reconciliation', 'The outcome of an earlier execution is unknown; reconcile it by hand.'),
            };

            if ($refusal) {
                $this->audit->record('approval.execution_refused', $request, ['reason' => $refusal->reasonCode], actorType: 'system');

                return [$request, $refusal];
            }

            if ($request->isExpired()) {
                $this->close($request, ApprovalStatus::Expired, 'approval_expired', 'The approval expired before execution.');

                return [$request, new ApprovalRefused('expired', 'The approval expired before execution.')];
            }

            $decision = $request->decision;
            $matches = $decision
                && $decision->decision === 'approved'
                && hash_equals($request->payload_hash, $hash)
                && hash_equals($decision->payload_hash, $hash)
                && $request->snapshotIntact();

            if (! $matches) {
                $this->close($request, ApprovalStatus::Invalidated, 'payload_mismatch', 'The action differs from what was approved.');
                $this->audit->record('approval.invalidated', $request, ['approved_hash' => $request->payload_hash, 'attempted_hash' => $hash], actorType: 'system');

                return [$request, new ApprovalRefused('payload_mismatch', 'The action differs from what was approved; the approval was invalidated.')];
            }

            $request->status = ApprovalStatus::Executing;
            $request->execution_started_at = now();
            $request->save();
            $this->audit->record('approval.execution_started', $request, ['payload_hash' => $hash], actorType: 'system');

            return [$request, null];
        });

        if ($refusal) {
            throw $refusal;
        }

        try {
            $result = $effect($request);
        } catch (Throwable $e) {
            $this->finish($request, ApprovalStatus::Failed, ['error' => Str::limit($e->getMessage(), 500)]);

            throw $e;
        }

        $this->finish($request, ApprovalStatus::Executed, $result);

        return $result;
    }

    /**
     * Expire open requests past their deadline and flag executions whose
     * outcome is unknown. Returns [expired, flagged].
     *
     * @return array{0: int, 1: int}
     */
    public function sweep(): array
    {
        $expired = 0;
        $ids = ApprovalRequest::query()
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])
            ->where('expires_at', '<', now())
            ->pluck('id');

        foreach ($ids as $id) {
            $expired += DB::transaction(function () use ($id) {
                $request = ApprovalRequest::query()->lockForUpdate()->find($id);

                if (! $request || ! in_array($request->status, [ApprovalStatus::Pending, ApprovalStatus::Approved], true) || ! $request->isExpired()) {
                    return 0;
                }

                $this->close($request, ApprovalStatus::Expired, 'approval_expired', 'The approval request expired.');

                return 1;
            });
        }

        $flagged = 0;
        $ids = ApprovalRequest::query()
            ->where('status', ApprovalStatus::Executing)
            ->where('execution_started_at', '<', now()->subMinutes(config('company_os.approvals.reconcile_after_minutes')))
            ->pluck('id');

        foreach ($ids as $id) {
            $flagged += DB::transaction(function () use ($id) {
                $request = ApprovalRequest::query()->lockForUpdate()->find($id);

                if (! $request || $request->status !== ApprovalStatus::Executing) {
                    return 0;
                }

                $request->status = ApprovalStatus::NeedsReconciliation;
                $request->save();
                $this->audit->record('approval.needs_reconciliation', $request, ['execution_started_at' => $request->execution_started_at?->toIso8601String()], actorType: 'system');

                return 1;
            });
        }

        return [$expired, $flagged];
    }

    /**
     * Re-dispatch approved tool calls that were held while paused.
     */
    public function dispatchApproved(): int
    {
        $ids = ApprovalRequest::query()
            ->where('status', ApprovalStatus::Approved)
            ->where('expires_at', '>', now())
            ->whereIn('id', ToolExecution::query()->select('approval_request_id')->where('status', ToolExecutionStatus::AwaitingApproval))
            ->pluck('id');

        foreach ($ids as $id) {
            ExecuteApprovedAction::dispatch($id)->afterCommit();
        }

        return $ids->count();
    }

    /**
     * Mark an approved request as unusable because a mandatory gate (agent
     * disabled, permission revoked) now refuses the action.
     */
    public function abandon(ApprovalRequest $request, string $code, string $message): void
    {
        DB::transaction(function () use ($request, $code, $message) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== ApprovalStatus::Approved) {
                return;
            }

            $request->status = ApprovalStatus::Failed;
            $request->execution_result = ['error' => $code, 'message' => $message];
            $request->save();
            $this->audit->record('approval.abandoned', $request, ['reason' => $code], actorType: 'system');
        });
    }

    private function sameAction(ApprovalRequest $existing, string $hash): ApprovalRequest
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            throw new LogicException('An approval request with this idempotency key exists for a different payload.');
        }

        return $existing;
    }

    /** Must be called inside a transaction holding the request's lock. */
    private function close(ApprovalRequest $request, ApprovalStatus $status, string $code, string $message): void
    {
        $request->status = $status;
        $request->save();
        $this->audit->record('approval.'.$status->value, $request, ['reason' => $code], actorType: 'system');
        $this->closeLinkedExecutions($request, $code, $message);
    }

    private function closeLinkedExecutions(ApprovalRequest $request, string $code, string $message): void
    {
        ToolExecution::query()
            ->where('approval_request_id', $request->id)
            ->where('status', ToolExecutionStatus::AwaitingApproval)
            ->update([
                'status' => ToolExecutionStatus::Denied,
                'error_code' => $code,
                'error_message' => $message,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(ApprovalRequest $request, ApprovalStatus $status, array $result): void
    {
        DB::transaction(function () use ($request, $status, $result) {
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($request->id);

            // Only the claimant finishes; a reconciliation flag set meanwhile stays.
            if ($request->status !== ApprovalStatus::Executing) {
                return;
            }

            $request->status = $status;
            $request->executed_at = $status === ApprovalStatus::Executed ? now() : null;
            $request->execution_result = $this->audit->redact($result);
            $request->save();
            $this->audit->record('approval.'.$status->value, $request, $result, actorType: 'system');
        });
    }
}
