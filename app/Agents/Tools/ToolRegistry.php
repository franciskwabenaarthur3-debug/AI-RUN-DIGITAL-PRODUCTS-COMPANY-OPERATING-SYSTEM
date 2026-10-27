<?php

namespace App\Agents\Tools;

use App\Agents\ToolCatalog;
use InvalidArgumentException;

/**
 * Implementations for catalog tools. A catalog tool without an
 * implementation is refused at call time.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /**
     * @param  iterable<Tool>  $tools
     */
    public function __construct(iterable $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(Tool $tool): void
    {
        if (! ToolCatalog::exists($tool->name()) || ToolCatalog::isProhibited($tool->name())) {
            throw new InvalidArgumentException("Tool \"{$tool->name()}\" is not in the tool catalog.");
        }

        $this->tools[$tool->name()] = $tool;
    }

    public function find(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }
}
