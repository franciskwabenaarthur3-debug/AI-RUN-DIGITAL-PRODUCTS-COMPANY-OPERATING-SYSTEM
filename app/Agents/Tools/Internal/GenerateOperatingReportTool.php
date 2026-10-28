<?php

namespace App\Agents\Tools\Internal;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\ToolContext;
use App\Agents\Tools\ToolResult;
use App\Enums\ReportPeriod;
use App\Services\OperatingReportService;
use Illuminate\Validation\Rule;

/**
 * Read-only: returns the operating report computed from records for the
 * current day, week or month. Nothing is stored or changed.
 */
class GenerateOperatingReportTool extends BaseTool
{
    public function __construct(private readonly OperatingReportService $reports) {}

    public function name(): string
    {
        return 'generate_operating_report';
    }

    public function rules(): array
    {
        return ['period' => ['required', Rule::enum(ReportPeriod::class)]];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['period' => ['type' => 'string', 'enum' => array_map(fn ($p) => $p->value, ReportPeriod::cases())]],
            'required' => ['period'],
            'additionalProperties' => false,
        ];
    }

    public function rollbackPlan(array $input): string
    {
        return 'Read-only; nothing to undo.';
    }

    public function handle(array $input, ToolContext $context): ToolResult
    {
        return new ToolResult($this->reports->build(ReportPeriod::from($input['period']), now()));
    }
}
