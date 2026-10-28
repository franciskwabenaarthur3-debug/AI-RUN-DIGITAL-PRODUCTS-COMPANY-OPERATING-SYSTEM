<?php

namespace App\Ai;

/**
 * One entry of the model catalog (config company_os.ai.models). Agents
 * refer to it by key, so a model can be swapped without touching any
 * workflow; the key and version are recorded on every run.
 */
final class ModelConfig
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $version,
        public readonly int $inputMinorPerMtok,
        public readonly int $outputMinorPerMtok,
        public readonly int $maxOutputTokens,
        public readonly ?string $effort,
        public readonly bool $webSearch,
    ) {}

    /**
     * Estimated cost in minor units, rounded up. Cache reads and writes
     * are priced as ordinary input, which overestimates slightly.
     */
    public function costMinor(int $inputTokens, int $outputTokens, int $webSearches = 0): int
    {
        $tokens = $inputTokens * $this->inputMinorPerMtok + $outputTokens * $this->outputMinorPerMtok;
        $searches = $webSearches * (int) config('company_os.ai.web_search_minor_per_1000');

        return intdiv($tokens + 999_999, 1_000_000) + intdiv($searches + 999, 1000);
    }
}
