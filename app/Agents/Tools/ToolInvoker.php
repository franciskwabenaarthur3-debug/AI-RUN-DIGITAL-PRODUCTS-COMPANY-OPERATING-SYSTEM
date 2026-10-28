<?php

namespace App\Agents\Tools;

use App\Models\ToolExecution;

/**
 * Handed to a runtime for one run. It is the only way a runtime can act:
 * every call goes through the gateway with this run's identity.
 */
final class ToolInvoker
{
    public function __construct(
        private readonly ToolGateway $gateway,
        private readonly ToolContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function call(string $tool, array $input): ToolExecution
    {
        return $this->gateway->call($this->context, $tool, $input);
    }

    /**
     * @return list<Tool>
     */
    public function available(): array
    {
        return $this->gateway->availableTools($this->context->agent);
    }

    public function mayUse(string $catalogTool): bool
    {
        return $this->context->agent->isEnabled() && $this->context->agent->mayUseTool($catalogTool);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function recordProviderTool(string $catalogTool, array $input, string $provider): ToolExecution
    {
        return $this->gateway->recordProviderTool($this->context, $catalogTool, $input, $provider);
    }
}
