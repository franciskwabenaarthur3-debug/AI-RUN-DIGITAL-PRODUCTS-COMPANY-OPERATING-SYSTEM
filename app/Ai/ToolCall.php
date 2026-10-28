<?php

namespace App\Ai;

final class ToolCall
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
    ) {}
}
