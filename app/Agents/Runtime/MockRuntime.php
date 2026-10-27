<?php

namespace App\Agents\Runtime;

use App\Enums\AgentRole;

/**
 * Deterministic runtime for development and tests. It performs no real
 * work and says so in its output. Scenarios can be forced with the task
 * input key `mock_scenario` to exercise failure handling:
 * ok, invalid_output, loop, token_heavy, timeout, error, flaky.
 */
class MockRuntime implements AgentRuntime
{
    public function name(): string
    {
        return 'mock';
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function maxRunCostMinor(RunRequest $request): int
    {
        return 0;
    }

    public function run(RunRequest $request): RunResult
    {
        $scenario = (string) ($request->input['mock_scenario'] ?? 'ok');

        return match ($scenario) {
            'invalid_output' => new RunResult(['status' => 'done', 'sent' => true], 1, [$this->usage(50, 10)]),
            'loop' => $this->loop($request),
            'token_heavy' => new RunResult($this->output($request), 1, [$this->usage($request->maxTokens, 1)]),
            'timeout' => throw RuntimeFailure::timeout(),
            'error' => throw RuntimeFailure::provider('Mock provider error.'),
            'fatal' => throw RuntimeFailure::provider('Mock non-retryable error.', retryable: false),
            'flaky' => $request->attempt === 1
                ? throw RuntimeFailure::provider('Mock transient error.')
                : new RunResult($this->output($request), 1, [$this->usage(100, 50)]),
            default => new RunResult($this->output($request), 1, [$this->usage(100, 50)]),
        };
    }

    /**
     * Simulates an agent that never decides it is finished: it stops at
     * the step limit rather than running forever.
     */
    private function loop(RunRequest $request): RunResult
    {
        for ($step = 1; ; $step++) {
            if ($step > $request->maxSteps) {
                throw new RuntimeFailure('step_limit_exceeded', "The run exceeded the limit of {$request->maxSteps} steps.", usage: [$this->usage(10 * $request->maxSteps, 0)]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function output(RunRequest $request): array
    {
        $output = [
            'kind' => $request->agent->role === AgentRole::Orchestrator ? 'recommendation' : 'draft',
            'summary' => "[Mock output] {$request->agent->name} received the task \"{$request->task->title}\". No real work was performed.",
            'items' => ['This result was produced by the mock runtime for testing the task pipeline.'],
            'assumptions' => ['Mock runtime: nothing in this output is verified.'],
        ];

        return $output;
    }

    private function usage(int $in, int $out): UsageRecord
    {
        return new UsageRecord('mock', 'mock-1', $in, $out, 0, 'USD');
    }
}
