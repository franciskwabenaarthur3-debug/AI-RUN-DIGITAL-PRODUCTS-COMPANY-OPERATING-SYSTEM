<?php

namespace App\Agents\Runtime;

use RuntimeException;

/**
 * A normalized runtime error. Codes are stable and stored on agent_runs.
 */
class RuntimeFailure extends RuntimeException
{
    /**
     * @param  list<UsageRecord>  $usage  usage already incurred before the failure
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly array $usage = [],
    ) {
        parent::__construct($message);
    }

    public static function timeout(string $message = 'The run exceeded its deadline.'): self
    {
        return new self('timeout', $message, retryable: true);
    }

    public static function stepLimit(int $max): self
    {
        return new self('step_limit_exceeded', "The run exceeded the limit of {$max} steps.");
    }

    public static function provider(string $message, bool $retryable = true): self
    {
        return new self('provider_error', $message, $retryable);
    }
}
