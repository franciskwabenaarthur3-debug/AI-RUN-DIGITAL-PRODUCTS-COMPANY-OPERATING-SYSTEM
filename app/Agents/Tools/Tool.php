<?php

namespace App\Agents\Tools;

use App\Enums\RiskLevel;

/**
 * A registered, policy-checked capability. Tools are called only through
 * ToolGateway, which checks permissions, validates input against rules()
 * and handles approval before handle() runs.
 */
interface Tool
{
    /** Must match a name in App\Agents\ToolCatalog::TOOLS. */
    public function name(): string;

    /**
     * Laravel validation rules for the input. Keys not listed here are
     * rejected.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * JSON Schema for the input, shown to models that call the tool. The
     * gateway still validates against rules(); this only describes it.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * The record the action targets, for approval requests and audit.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string|null, 1: int|null}
     */
    public function resource(array $input): array;

    public function riskLevel(): RiskLevel;

    /**
     * How to undo the action if it turns out to be wrong. Shown to the
     * approver for Level 2 tools.
     *
     * @param  array<string, mixed>  $input
     */
    public function rollbackPlan(array $input): string;

    /**
     * Perform the action. Runs outside any gateway transaction.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, ToolContext $context): ToolResult;
}
