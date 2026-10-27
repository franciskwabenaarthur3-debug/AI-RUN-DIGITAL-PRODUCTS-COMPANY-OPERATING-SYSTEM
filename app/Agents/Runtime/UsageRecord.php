<?php

namespace App\Agents\Runtime;

final class UsageRecord
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $estimatedCostMinor,
        public readonly string $currency,
    ) {}
}
