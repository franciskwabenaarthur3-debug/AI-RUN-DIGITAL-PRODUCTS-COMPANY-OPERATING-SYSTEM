<?php

namespace App\Ai;

final class ModelRequest
{
    /**
     * @param  list<array<string, mixed>>  $messages  built by the provider's message helpers
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     */
    public function __construct(
        public readonly ModelConfig $model,
        public readonly string $system,
        public readonly array $messages,
        public readonly array $tools,
        public readonly int $maxTokens,
        public readonly float $timeoutSeconds,
        public readonly int $webSearchMaxUses = 0,
    ) {}
}
