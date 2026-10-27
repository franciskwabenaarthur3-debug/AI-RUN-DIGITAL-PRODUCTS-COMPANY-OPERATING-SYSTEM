<?php

namespace App\Agents\Runtime;

/**
 * Executes one agent run. Implementations must respect the request's step,
 * token and deadline limits; the runner re-checks them regardless.
 */
interface AgentRuntime
{
    public function name(): string;

    /** Whether runs cost money and therefore count against budgets. */
    public function isPaid(): bool;

    /** Upper bound on the cost of one run, used for the pre-run budget check. */
    public function maxRunCostMinor(RunRequest $request): int;

    /**
     * @throws RuntimeFailure
     */
    public function run(RunRequest $request): RunResult;
}
