<?php

namespace App\Agents\Tools;

final class ToolResult
{
    /**
     * @param  array<string, mixed>  $summary  stored on the execution record; keep it small
     */
    public function __construct(
        public readonly array $summary,
        public readonly ?string $externalReference = null,
    ) {}
}
