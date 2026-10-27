<?php

namespace App\Agents\Runtime;

final class RunResult
{
    /**
     * @param  list<UsageRecord>  $usage
     */
    public function __construct(
        public readonly mixed $output,
        public readonly int $steps,
        public readonly array $usage = [],
    ) {}

    public function totalTokens(): int
    {
        return array_sum(array_map(fn (UsageRecord $u) => $u->inputTokens + $u->outputTokens, $this->usage));
    }
}
