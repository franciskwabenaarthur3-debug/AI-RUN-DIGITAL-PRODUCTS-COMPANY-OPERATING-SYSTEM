<?php

namespace App\Agents\Runtime;

final class RunResult
{
    /**
     * @param  list<UsageRecord>  $usage
     * @param  array<string, scalar>  $metadata  stored on the run, e.g. the model configuration used
     */
    public function __construct(
        public readonly mixed $output,
        public readonly int $steps,
        public readonly array $usage = [],
        public readonly array $metadata = [],
    ) {}

    public function totalTokens(): int
    {
        return array_sum(array_map(fn (UsageRecord $u) => $u->inputTokens + $u->outputTokens, $this->usage));
    }
}
