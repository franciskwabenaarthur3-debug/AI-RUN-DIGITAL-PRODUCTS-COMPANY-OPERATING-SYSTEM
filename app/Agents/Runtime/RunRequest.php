<?php

namespace App\Agents\Runtime;

use App\Agents\Tools\ToolInvoker;
use App\Models\Agent;
use App\Models\Task;
use Carbon\CarbonImmutable;

final class RunRequest
{
    /** Set once the run exists; the runtime's only way to act. */
    public ?ToolInvoker $tools = null;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly Agent $agent,
        public readonly Task $task,
        public readonly array $input,
        public readonly int $attempt,
        public readonly int $maxSteps,
        public readonly int $maxTokens,
        public readonly CarbonImmutable $deadline,
        public readonly ?string $correlationId,
    ) {}
}
