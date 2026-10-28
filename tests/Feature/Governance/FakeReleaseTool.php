<?php

namespace Tests\Feature\Governance;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Enums\RiskLevel;
use RuntimeException;

/**
 * Test-only Level 2 tool ("prepare_release" in the catalog) that counts
 * how often its side effect runs.
 */
class FakeReleaseTool extends BaseTool
{
    public int $runs = 0;

    public bool $explode = false;

    /** @var (\Closure(): void)|null */
    public ?\Closure $during = null;

    public function name(): string
    {
        return 'prepare_release';
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['version' => ['type' => 'string'], 'notes' => ['type' => 'string']], 'required' => ['version']];
    }

    public function riskLevel(): RiskLevel
    {
        return RiskLevel::High;
    }

    public function rollbackPlan(array $input): string
    {
        return 'Delete the release draft.';
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        if ($this->during) {
            ($this->during)();
        }

        if ($this->explode) {
            throw new RuntimeException('Release service unavailable.');
        }

        $this->runs++;

        return new ToolResult(['version' => $input['version']], 'release-'.$input['version']);
    }
}
