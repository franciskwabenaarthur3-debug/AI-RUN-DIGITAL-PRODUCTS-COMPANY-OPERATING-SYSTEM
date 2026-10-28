<?php

namespace App\Agents\Tools;

use App\Agents\ToolCatalog;
use App\Enums\ToolExecutionStatus;
use App\Exceptions\InvalidTransition;
use App\Governance\ApprovalRefused;
use App\Governance\Payload;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\CompanySetting;
use App\Models\ToolExecution;
use App\Services\ApprovalService;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The only path from an agent to an action. In order, a call is refused
 * when the tool is prohibited (Level 3), unknown or unimplemented, the
 * agent is disabled, the company is paused, the agent lacks the
 * permission, the run used its tool-call allowance, or the input fails
 * validation. A repeated call with the same input in the same run returns
 * the first execution instead of acting twice. Level 2 tools create an
 * approval request and run only after the founder approves, through
 * executeApproved(). Every outcome is recorded and audited.
 */
class ToolGateway
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function call(ToolContext $context, string $name, array $input): ToolExecution
    {
        $agent = Agent::query()->findOrFail($context->agent->id);
        $tool = $this->registry->find($name);

        $refusal = match (true) {
            ToolCatalog::isProhibited($name) => ['prohibited', "\"{$name}\" is a prohibited action and can never be run by an agent."],
            ! ToolCatalog::exists($name) || ! $tool => ['unknown_tool', "\"{$name}\" is not an available tool."],
            ! $agent->isEnabled() => ['agent_disabled', "Agent \"{$agent->name}\" is disabled."],
            CompanySetting::current()->isPaused() => ['paused', 'Company operations are paused.'],
            ! $agent->mayUseTool($name) => ['not_permitted', "Agent \"{$agent->name}\" is not permitted to use \"{$name}\"."],
            default => null,
        };

        if ($refusal) {
            return $this->deny($context, $name, $input, ...$refusal);
        }

        $unknown = array_diff(array_keys($input), array_unique(array_map(fn ($k) => explode('.', $k)[0], array_keys($tool->rules()))));
        if ($unknown !== []) {
            return $this->deny($context, $name, $input, 'invalid_input', 'Unknown input fields: '.implode(', ', $unknown).'.');
        }

        $validator = Validator::make($input, $tool->rules());
        if ($validator->fails()) {
            return $this->deny($context, $name, $input, 'invalid_input', implode(' ', $validator->errors()->all()));
        }
        $input = $validator->validated();

        $inputHash = hash('sha256', Payload::canonical($input));
        $key = hash('sha256', ($context->run?->id ?? 'none').'|'.$name.'|'.$inputHash);

        if ($existing = $this->existing($name, $key)) {
            return $existing;
        }

        try {
            $execution = DB::transaction(function () use ($context, $agent, $tool, $name, $input, $inputHash, $key) {
                if ($context->run) {
                    AgentRun::query()->lockForUpdate()->findOrFail($context->run->id);
                    $used = ToolExecution::query()->where('agent_run_id', $context->run->id)->count();

                    if ($used >= $agent->max_tool_calls_per_run) {
                        return null;
                    }
                }

                $needsApproval = ToolCatalog::requiresApproval($name);
                $execution = ToolExecution::query()->create([
                    'agent_run_id' => $context->run?->id,
                    'agent_id' => $agent->id,
                    'tool_name' => $name,
                    'idempotency_key' => $key,
                    'status' => $needsApproval ? ToolExecutionStatus::AwaitingApproval : ToolExecutionStatus::Running,
                    'input_hash' => $inputHash,
                    'input_summary' => $this->summarize($input),
                    'started_at' => now(),
                ]);

                if ($needsApproval) {
                    [$resourceType, $resourceId] = $tool->resource($input);
                    $approval = $this->approvals->request(
                        actionType: $name,
                        payload: $input,
                        reason: "Agent \"{$agent->name}\" wants to run \"{$name}\": ".ToolCatalog::TOOLS[$name][0].'.',
                        risk: $tool->riskLevel(),
                        rollbackPlan: $tool->rollbackPlan($input),
                        idempotencyKey: 'tool:'.$key,
                        requester: $agent,
                        resourceType: $resourceType,
                        resourceId: $resourceId,
                        task: $context->task,
                    );
                    $execution->approval_request_id = $approval->id;
                    $execution->save();
                }

                $this->audit->record($needsApproval ? 'tool.approval_requested' : 'tool.started', $execution, ['tool' => $name, 'run_id' => $context->run?->id], $agent);

                return $execution;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existing($name, $key);
        }

        if (! $execution) {
            return $this->deny($context, $name, $input, 'tool_call_limit', "The run reached its limit of {$agent->max_tool_calls_per_run} tool calls.");
        }

        if ($execution->status === ToolExecutionStatus::AwaitingApproval) {
            return $execution;
        }

        try {
            $result = $tool->handle($input, $context);
        } catch (Throwable $e) {
            return $this->failed($execution, $agent, $e);
        }

        return $this->succeeded($execution, $agent, $result);
    }

    /**
     * Run the tool call behind an approved request. Mandatory gates are
     * re-checked: an approval never overrides a disabled agent or a
     * revoked permission. While the company is paused nothing runs; the
     * call is re-dispatched when operations resume.
     */
    public function executeApproved(int $approvalId): ?ToolExecution
    {
        $request = ApprovalRequest::query()->find($approvalId);
        $execution = ToolExecution::query()
            ->where('approval_request_id', $approvalId)
            ->where('status', ToolExecutionStatus::AwaitingApproval)
            ->first();

        if (! $request || ! $execution) {
            return null;
        }

        if (CompanySetting::current()->isPaused()) {
            return null;
        }

        $agent = Agent::query()->findOrFail($execution->agent_id);
        $tool = $this->registry->find($execution->tool_name);

        $gate = match (true) {
            ToolCatalog::isProhibited($execution->tool_name) || ! $tool => ['unknown_tool', 'The tool is no longer available.'],
            ! $agent->isEnabled() => ['agent_disabled', "Agent \"{$agent->name}\" is disabled."],
            ! $agent->mayUseTool($execution->tool_name) => ['not_permitted', "Agent \"{$agent->name}\" is no longer permitted to use \"{$execution->tool_name}\"."],
            default => null,
        };

        if ($gate) {
            $this->approvals->abandon($request, $gate[0], $gate[1]);

            return $this->closeExecution($execution, $agent, ToolExecutionStatus::Denied, $gate[0], $gate[1]);
        }

        $run = $execution->run;
        $context = new ToolContext($agent, $run, $run?->task ?? $request->task, $request);
        $input = $request->payload_snapshot;
        [$resourceType, $resourceId] = [$request->resource_type, $request->resource_id];

        $result = null;

        try {
            $this->approvals->execute($request, $execution->tool_name, $resourceType, $resourceId, $input, function () use ($execution, $tool, $input, $context, &$result) {
                $execution->forceFill(['status' => ToolExecutionStatus::Running])->save();
                $result = $tool->handle($input, $context);

                return $result->summary;
            });
        } catch (ApprovalRefused $refused) {
            // Not decided yet, a duplicate delivery while another worker runs it,
            // or one after it ran: leave the record alone.
            if (in_array($refused->reasonCode, ['pending', 'in_progress', 'already_executed'], true)) {
                return $execution->fresh();
            }

            return $this->closeExecution($execution, $agent, ToolExecutionStatus::Denied, $refused->reasonCode, $refused->getMessage());
        } catch (Throwable $e) {
            return $this->failed($execution, $agent, $e);
        }

        return $this->succeeded($execution, $agent, $result);
    }

    private function existing(string $name, string $key): ?ToolExecution
    {
        return ToolExecution::query()->where('tool_name', $name)->where('idempotency_key', $key)->first();
    }

    /**
     * Denials are records of attempts, not idempotent operations, so each
     * gets its own key and a later valid call is not blocked by them.
     *
     * @param  array<string, mixed>  $input
     */
    private function deny(ToolContext $context, string $name, array $input, string $code, string $message): ToolExecution
    {
        $execution = ToolExecution::query()->create([
            'agent_run_id' => $context->run?->id,
            'agent_id' => $context->agent->id,
            'tool_name' => Str::limit($name, 60, ''),
            'idempotency_key' => 'denied:'.Str::uuid(),
            'status' => ToolExecutionStatus::Denied,
            'input_hash' => hash('sha256', Payload::canonical($input)),
            'input_summary' => $this->summarize($input),
            'error_code' => $code,
            'error_message' => $message,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->audit->record($code === 'prohibited' ? 'tool.prohibited' : 'tool.denied', $execution, [
            'tool' => $name,
            'reason' => $code,
            'run_id' => $context->run?->id,
        ], $context->agent);

        return $execution;
    }

    private function succeeded(ToolExecution $execution, Agent $agent, ToolResult $result): ToolExecution
    {
        $execution->forceFill([
            'status' => ToolExecutionStatus::Succeeded,
            'result_summary' => $this->audit->redact($result->summary),
            'external_reference' => $result->externalReference,
            'finished_at' => now(),
        ])->save();

        $this->audit->record('tool.succeeded', $execution, ['tool' => $execution->tool_name, 'approval_request_id' => $execution->approval_request_id], $agent);

        return $execution;
    }

    private function failed(ToolExecution $execution, Agent $agent, Throwable $e): ToolExecution
    {
        // Domain errors are safe to show; anything else is reported, not echoed.
        if ($e instanceof InvalidTransition) {
            $message = $e->getMessage();
        } elseif ($e instanceof ValidationException) {
            $message = implode(' ', $e->validator->errors()->all());
        } else {
            report($e);
            $message = 'The tool raised an unexpected error.';
        }

        return $this->closeExecution($execution, $agent, ToolExecutionStatus::Failed, 'tool_error', $message);
    }

    private function closeExecution(ToolExecution $execution, Agent $agent, ToolExecutionStatus $status, string $code, string $message): ToolExecution
    {
        $execution->forceFill([
            'status' => $status,
            'error_code' => $code,
            'error_message' => Str::limit($message, 2000),
            'finished_at' => now(),
        ])->save();

        $this->audit->record($status === ToolExecutionStatus::Denied ? 'tool.denied' : 'tool.failed', $execution, [
            'tool' => $execution->tool_name,
            'reason' => $code,
        ], $agent);

        return $execution;
    }

    /**
     * Redacted, size-limited copy of the input for display.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function summarize(array $input): array
    {
        $limit = function (mixed $v) use (&$limit): mixed {
            return match (true) {
                is_string($v) => Str::limit($v, 500),
                is_array($v) => array_map($limit, array_slice($v, 0, 50, true)),
                default => $v,
            };
        };

        return $limit($this->audit->redact($input));
    }
}
