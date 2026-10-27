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
}
