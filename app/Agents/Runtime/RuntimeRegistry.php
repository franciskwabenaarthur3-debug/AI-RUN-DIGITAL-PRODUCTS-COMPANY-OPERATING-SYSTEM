<?php

namespace App\Agents\Runtime;

/**
 * Resolves an agent's configured runtime. Only runtimes registered here can
 * run; the mock runtime is available only where config allows it.
 */
class RuntimeRegistry
{
    /**
     * @param  array<string, AgentRuntime>  $runtimes
     */
    public function __construct(private array $runtimes = []) {}

    public function register(AgentRuntime $runtime): void
    {
        $this->runtimes[$runtime->name()] = $runtime;
    }

    public function find(string $name): ?AgentRuntime
    {
        if ($name === 'mock' && ! config('company_os.agents.allow_mock_runtime')) {
            return null;
        }

        return $this->runtimes[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->runtimes);
    }
}
